-- AushadhBox 2.0: family app core (Supabase / PostgreSQL 15+).
-- Every family table carries household_id, and row-level security lets a signed-in person reach only the
-- households they belong to. Roles: owner (everything), caregiver (add and edit), viewer (read only).
-- Apply with:  supabase db push   (or paste into the Supabase SQL editor)

-- ---------- Helpers ----------
create or replace function public.touch_updated_at() returns trigger
language plpgsql set search_path = '' as $$
begin
  new.updated_at := now();
  return new;
end $$;

-- ---------- People who sign in ----------
create table public.profiles (
  id                 uuid primary key references auth.users (id) on delete cascade,
  display_name       text check (char_length(display_name) between 1 and 80),
  lang               text not null default 'en' check (lang in ('en','ta','hi','te','kn','ml','bn','mr','gu','pa')),
  adult_confirmed_at timestamptz,
  marketing_ok       boolean not null default false,   -- tips and offers: off unless chosen
  weekly_email       boolean not null default true,    -- weekly summary of the family's medicines
  nominee_name       text check (char_length(nominee_name) <= 80),
  nominee_contact    text check (char_length(nominee_contact) <= 120),
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now()
);

create table public.households (
  id         uuid primary key default gen_random_uuid(),
  name       text not null check (char_length(name) between 1 and 80),
  created_by uuid not null default auth.uid() references auth.users (id) on delete cascade,
  created_at timestamptz not null default now()
);

create table public.household_users (
  household_id uuid not null references public.households (id) on delete cascade,
  user_id      uuid not null references auth.users (id) on delete cascade,
  role         text not null check (role in ('owner','caregiver','viewer')),
  created_at   timestamptz not null default now(),
  primary key (household_id, user_id)
);
create index household_users_user on public.household_users (user_id);

-- security definer so the policies below can ask "is this person in that household?" without recursion
create or replace function public.is_member(h uuid, roles text[] default array['owner','caregiver','viewer'])
returns boolean language sql stable security definer set search_path = '' as $$
  select exists (
    select 1 from public.household_users hu
    where hu.household_id = h and hu.user_id = auth.uid() and hu.role = any (roles)
  )
$$;

create or replace function public.can_edit(h uuid) returns boolean
language sql stable security definer set search_path = '' as $$
  select public.is_member(h, array['owner','caregiver'])
$$;

-- ---------- Family members (the people medicines are for) ----------
create table public.people (
  id                  uuid primary key default gen_random_uuid(),
  household_id        uuid not null references public.households (id) on delete cascade,
  name                text not null check (char_length(name) between 1 and 60),
  relation            text check (char_length(relation) <= 30),
  is_self             boolean not null default false,
  birth_date          date check (birth_date > date '1900-01-01'),
  blood_group         text check (blood_group in ('A+','A-','B+','B-','AB+','AB-','O+','O-')),
  allergies           text check (char_length(allergies) <= 500),
  conditions          text check (char_length(conditions) <= 1600),
  emergency_contact   text check (char_length(emergency_contact) <= 400),
  guardian_consent_at timestamptz,   -- DPDP Act s.9: a parent or guardian agreed for a child under 18
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now(),
  unique (id, household_id)
);
create index people_household on public.people (household_id);

create or replace function public.people_guardian_check() returns trigger
language plpgsql set search_path = '' as $$
begin
  if new.birth_date is not null and new.birth_date > current_date - interval '18 years'
     and new.guardian_consent_at is null then
    raise exception 'guardian_consent_required' using errcode = 'P0001',
      hint = 'A parent or guardian must agree before details of a child under 18 are saved.';
  end if;
  return new;
end $$;

create trigger people_guardian before insert or update on public.people
  for each row execute function public.people_guardian_check();
create trigger people_touch before update on public.people
  for each row execute function public.touch_updated_at();

-- ---------- Medicines in the cabinet ----------
create table public.medicines (
  id           uuid primary key default gen_random_uuid(),
  household_id uuid not null references public.households (id) on delete cascade,
  person_id    uuid,                         -- null = for the whole family
  name         text not null check (char_length(name) between 1 and 120),
  strength     text check (char_length(strength) <= 40),
  form         text check (form in ('tablet','capsule','syrup','drops','injection','cream','inhaler','powder','other')),
  batch_no     text check (char_length(batch_no) <= 40),
  exp_month    date,                         -- usable until the END of this month; null = not known
  dose_times   text[] not null default '{}' check (dose_times <@ array['m','a','e','b']),
  per_dose     smallint not null default 1 check (per_dose between 1 and 20),
  stock_count  integer check (stock_count between 0 and 10000),
  course_end   date,
  food         text check (food in ('before','after','with','empty','any')),
  notes        text check (char_length(notes) <= 300),
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now(),
  unique (id, household_id),
  -- the person must be in the same household (no linking to another family's member)
  foreign key (person_id, household_id) references public.people (id, household_id) on delete set null (person_id)
);
create index medicines_household_exp on public.medicines (household_id, exp_month);
create trigger medicines_touch before update on public.medicines
  for each row execute function public.touch_updated_at();

-- ---------- Doses taken or skipped (no row = not confirmed) ----------
create table public.dose_logs (
  id           uuid primary key default gen_random_uuid(),
  household_id uuid not null,
  medicine_id  uuid not null,
  slot         text not null check (slot in ('m','a','e','b')),
  taken_on     date not null,
  status       text not null default 'taken' check (status in ('taken','skipped')),
  reason       text check (reason in ('fine','side','out','doctor','other')),
  taken_at     timestamptz not null default now(),
  taken_by     uuid default auth.uid() references auth.users (id) on delete set null,
  unique (medicine_id, slot, taken_on),
  foreign key (medicine_id, household_id) references public.medicines (id, household_id) on delete cascade
);
create index dose_logs_day on public.dose_logs (household_id, taken_on);

-- a confirmed dose uses tablets from the stock count; undoing it puts them back
create or replace function public.dose_stock() returns trigger
language plpgsql set search_path = '' as $$
begin
  if tg_op in ('DELETE','UPDATE') and old.status = 'taken' then
    update public.medicines set stock_count = stock_count + per_dose
     where id = old.medicine_id and stock_count is not null;
  end if;
  if tg_op in ('INSERT','UPDATE') and new.status = 'taken' then
    update public.medicines set stock_count = greatest(0, stock_count - per_dose)
     where id = new.medicine_id and stock_count is not null;
  end if;
  return null;
end $$;
create trigger dose_logs_stock after insert or update of status or delete on public.dose_logs
  for each row execute function public.dose_stock();

-- ---------- Health readings, doctor visits, expenses ----------
create table public.vitals (
  id           uuid primary key default gen_random_uuid(),
  household_id uuid not null references public.households (id) on delete cascade,
  person_id    uuid,
  kind         text not null check (kind in ('bp','sugar','weight','temp','spo2','pulse')),
  v1           numeric(6,1) not null check (v1 > 0 and v1 < 2000),
  v2           numeric(6,1) check (v2 > 0 and v2 < 400),
  context      text check (context in ('fasting','after_meal','random')),
  note         text check (char_length(note) <= 200),
  taken_at     timestamptz not null default now(),
  foreign key (person_id, household_id) references public.people (id, household_id) on delete cascade
);
create index vitals_person on public.vitals (household_id, person_id, kind, taken_at desc);

create table public.appointments (
  id           uuid primary key default gen_random_uuid(),
  household_id uuid not null references public.households (id) on delete cascade,
  person_id    uuid,
  doctor       text not null check (char_length(doctor) between 1 and 80),
  place        text check (char_length(place) <= 120),
  at           timestamptz not null,
  questions    text check (char_length(questions) <= 500),
  created_at   timestamptz not null default now(),
  foreign key (person_id, household_id) references public.people (id, household_id) on delete cascade
);
create index appointments_at on public.appointments (household_id, at);

create table public.expenses (
  id           uuid primary key default gen_random_uuid(),
  household_id uuid not null references public.households (id) on delete cascade,
  person_id    uuid,
  category     text not null check (category in ('medicine','test','consult','checkup','other')),
  amount       numeric(10,2) not null check (amount > 0 and amount < 10000000),
  spent_on     date not null,
  note         text check (char_length(note) <= 160),
  created_at   timestamptz not null default now(),
  foreign key (person_id, household_id) references public.people (id, household_id) on delete set null (person_id)
);
create index expenses_day on public.expenses (household_id, spent_on);

-- ---------- Reference data anyone may read ----------
create table public.medicine_info (
  id          serial primary key,
  ingredient  text not null unique,
  keywords    text not null default '',
  use_text    text not null,
  rx          boolean not null default true,
  storage     text,
  food        text,
  usual_times text[] not null default '{}'
);

-- ---------- Privacy (DPDP Act 2023; ISO/IEC 29100 principles, ISO/IEC 27701 records) ----------
create table public.consent_records (
  id         uuid primary key default gen_random_uuid(),
  user_id    uuid not null default auth.uid() references auth.users (id) on delete cascade,
  purpose    text not null check (purpose in ('terms','privacy','adult','marketing','weekly_email','ai','guardian','pharmacy_view','pharmacy_add')),
  granted    boolean not null,
  version    text not null default '2026-11',
  created_at timestamptz not null default now()
);
create index consent_records_user on public.consent_records (user_id, created_at desc);

create table public.privacy_requests (
  id         uuid primary key default gen_random_uuid(),
  user_id    uuid not null default auth.uid() references auth.users (id) on delete cascade,
  kind       text not null check (kind in ('access','correction','erasure','grievance','withdraw')),
  details    text check (char_length(details) <= 2000),
  status     text not null default 'open' check (status in ('open','in_progress','done','rejected')),
  reply      text check (char_length(reply) <= 2000),
  due_on     date not null default (current_date + 30),
  created_at timestamptz not null default now()
);

create table public.audit_log (
  id           bigserial primary key,
  household_id uuid references public.households (id) on delete cascade,
  actor        uuid,
  action       text not null,
  table_name   text not null,
  row_id       uuid,
  at           timestamptz not null default now()
);
create index audit_log_household on public.audit_log (household_id, at desc);

create or replace function public.audit_row() returns trigger
language plpgsql security definer set search_path = '' as $$
declare r record;
begin
  if tg_op = 'DELETE' then r := old; else r := new; end if;
  -- rows removed because the whole household is being deleted: nothing left to log against
  if not exists (select 1 from public.households where id = r.household_id) then return null; end if;
  insert into public.audit_log (household_id, actor, action, table_name, row_id)
  values (r.household_id, auth.uid(), lower(tg_op), tg_table_name, r.id);
  return null;
end $$;

create trigger people_audit after insert or update or delete on public.people
  for each row execute function public.audit_row();
create trigger medicines_audit after insert or delete or update of name, strength, person_id, exp_month, dose_times, per_dose, course_end
  on public.medicines
  for each row execute function public.audit_row();

-- ---------- New accounts: profile, a household, its owner row and a "Me" person ----------
create or replace function public.handle_new_user() returns trigger
language plpgsql security definer set search_path = '' as $$
declare
  hh uuid;
  meta jsonb := coalesce(new.raw_user_meta_data, '{}'::jsonb);
  nm text := nullif(left(trim(coalesce(meta ->> 'display_name', '')), 60), '');
begin
  insert into public.profiles (id, display_name, adult_confirmed_at, marketing_ok)
  values (new.id, nm,
          case when (meta ->> 'adult')::boolean then now() end,
          coalesce((meta ->> 'marketing')::boolean, false));
  insert into public.households (name, created_by)
  values (coalesce(nm || '''s family', 'My family'), new.id) returning id into hh;   -- households_owner adds the owner row
  insert into public.people (household_id, name, relation, is_self)
  values (hh, coalesce(nm, 'Me'), 'self', true);
  insert into public.consent_records (user_id, purpose, granted)
  values (new.id, 'terms', true), (new.id, 'privacy', true),
         (new.id, 'adult', coalesce((meta ->> 'adult')::boolean, false)),
         (new.id, 'marketing', coalesce((meta ->> 'marketing')::boolean, false));
  return new;
end $$;

create trigger on_auth_user_created after insert on auth.users
  for each row execute function public.handle_new_user();

-- a household someone creates later in the app: its creator becomes the owner
create or replace function public.households_add_owner() returns trigger
language plpgsql security definer set search_path = '' as $$
begin
  insert into public.household_users (household_id, user_id, role)
  values (new.id, new.created_by, 'owner') on conflict do nothing;
  return new;
end $$;
create trigger households_owner after insert on public.households
  for each row execute function public.households_add_owner();

-- erase my account and everything only I own (DPDP s.12 right to erasure)
create or replace function public.delete_my_account() returns void
language plpgsql security definer set search_path = '' as $$
begin
  if auth.uid() is null then raise exception 'not_signed_in'; end if;
  delete from auth.users where id = auth.uid();
end $$;

-- ---------- Row-level security ----------
alter table public.profiles         enable row level security;
alter table public.households       enable row level security;
alter table public.household_users  enable row level security;
alter table public.people           enable row level security;
alter table public.medicines        enable row level security;
alter table public.dose_logs        enable row level security;
alter table public.vitals           enable row level security;
alter table public.appointments     enable row level security;
alter table public.expenses         enable row level security;
alter table public.medicine_info    enable row level security;
alter table public.consent_records  enable row level security;
alter table public.privacy_requests enable row level security;
alter table public.audit_log        enable row level security;

create policy profiles_own_select on public.profiles for select to authenticated using (id = auth.uid());
create policy profiles_own_update on public.profiles for update to authenticated using (id = auth.uid()) with check (id = auth.uid());

create policy households_select on public.households for select to authenticated using (public.is_member(id));
create policy households_insert on public.households for insert to authenticated with check (created_by = auth.uid());
create policy households_update on public.households for update to authenticated
  using (public.is_member(id, array['owner'])) with check (public.is_member(id, array['owner']));
create policy households_delete on public.households for delete to authenticated using (public.is_member(id, array['owner']));

create policy hu_select on public.household_users for select to authenticated using (public.is_member(household_id));
create policy hu_owner_write on public.household_users for all to authenticated
  using (public.is_member(household_id, array['owner'])) with check (public.is_member(household_id, array['owner']));
create policy hu_leave on public.household_users for delete to authenticated using (user_id = auth.uid());

-- the same four rules for every family table
do $$
declare t text;
begin
  foreach t in array array['people','medicines','dose_logs','vitals','appointments','expenses'] loop
    execute format('create policy %1$s_select on public.%1$s for select to authenticated using (public.is_member(household_id))', t);
    execute format('create policy %1$s_insert on public.%1$s for insert to authenticated with check (public.can_edit(household_id))', t);
    execute format('create policy %1$s_update on public.%1$s for update to authenticated using (public.can_edit(household_id)) with check (public.can_edit(household_id))', t);
    execute format('create policy %1$s_delete on public.%1$s for delete to authenticated using (public.can_edit(household_id))', t);
  end loop;
end $$;

create policy medicine_info_read on public.medicine_info for select to anon, authenticated using (true);

create policy consent_own_select on public.consent_records for select to authenticated using (user_id = auth.uid());
create policy consent_own_insert on public.consent_records for insert to authenticated with check (user_id = auth.uid());

create policy pr_own_select on public.privacy_requests for select to authenticated using (user_id = auth.uid());
create policy pr_own_insert on public.privacy_requests for insert to authenticated
  with check (user_id = auth.uid() and status = 'open' and reply is null);

create policy audit_owner_select on public.audit_log for select to authenticated
  using (public.is_member(household_id, array['owner']));

-- ---------- Table privileges (RLS above decides which rows) ----------
revoke all on all tables in schema public from anon, authenticated;
grant select on public.medicine_info to anon, authenticated;
grant select, update (display_name, lang, marketing_ok, weekly_email, nominee_name, nominee_contact, updated_at)
  on public.profiles to authenticated;
grant select, insert (name), update (name), delete on public.households to authenticated;
grant select, insert, update (role), delete on public.household_users to authenticated;
grant select, insert, update, delete on public.people, public.medicines, public.dose_logs,
  public.vitals, public.appointments, public.expenses to authenticated;
grant select, insert (purpose, granted, version) on public.consent_records to authenticated;
grant select, insert (kind, details) on public.privacy_requests to authenticated;
grant select on public.audit_log to authenticated;
revoke all on function public.delete_my_account() from public, anon;
grant execute on function public.delete_my_account() to authenticated;
grant execute on function public.is_member(uuid, text[]), public.can_edit(uuid) to authenticated;
