// Database access rules, tested against the real migration in an in-memory Postgres (PGlite).
// Each "user" runs as the Supabase role `authenticated` with their id in request.jwt.claim.sub, as Supabase does.
import { PGlite } from '@electric-sql/pglite';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { beforeAll, describe, expect, it } from 'vitest';

const root = join(__dirname, '../../../supabase');
const A = 'aaaaaaaa-0000-0000-0000-000000000001';
const B = 'bbbbbbbb-0000-0000-0000-000000000002';
const C = 'cccccccc-0000-0000-0000-000000000003';
let db: PGlite;

async function as(user: string | null, sql: string, params: unknown[] = []) {
  await db.exec('reset role');
  if (user) {
    await db.query(`select set_config('request.jwt.claim.sub', $1, false)`, [user]);
    await db.exec('set role authenticated');
  } else {
    await db.query(`select set_config('request.jwt.claim.sub', '', false)`);
    await db.exec('set role anon');
  }
  try { return (await db.query<Record<string, any>>(sql, params)).rows; } finally { await db.exec('reset role'); }
}
const fails = async (p: Promise<unknown>, match?: RegExp) => {
  let err: any = null;
  try { await p; } catch (e) { err = e; }
  expect(err, 'expected the statement to be refused').not.toBeNull();
  if (match) expect(String(err.message)).toMatch(match);
};
const hh = async (u: string) => (await as(u, 'select id from households'))[0].id as string;

beforeAll(async () => {
  db = new PGlite();
  await db.exec(readFileSync(join(root, 'tests/supabase_shim.sql'), 'utf8'));
  for (const f of readdirSync(join(root, 'migrations')).sort()) await db.exec(readFileSync(join(root, 'migrations', f), 'utf8'));
  await db.query(`insert into auth.users values ($1,'a@test','{"display_name":"Asha","adult":true,"marketing":false}'),
                                                ($2,'b@test','{"display_name":"Bala","adult":true}'),
                                                ($3,'c@test','{}')`, [A, B, C]);
});

describe('sign-up', () => {
  it('creates a profile, a household with an owner, a "me" person and consent records', async () => {
    const p = await as(A, 'select display_name, adult_confirmed_at, marketing_ok from profiles');
    expect(p).toHaveLength(1);
    expect(p[0].display_name).toBe('Asha');
    expect(p[0].adult_confirmed_at).not.toBeNull();
    expect(p[0].marketing_ok).toBe(false);
    expect(await as(A, 'select role from household_users')).toEqual([{ role: 'owner' }]);
    expect((await as(A, 'select name, is_self from people'))[0]).toEqual({ name: 'Asha', is_self: true });
    expect((await as(A, 'select purpose from consent_records order by purpose')).map(r => r.purpose)).toEqual(['adult', 'marketing', 'privacy', 'terms']);
  });
});

describe('families are separated', () => {
  it('each person sees only their own household', async () => {
    expect(await as(A, 'select name from households')).toEqual([{ name: "Asha's family" }]);
    expect(await as(B, 'select name from households')).toEqual([{ name: "Bala's family" }]);
    expect(await as(B, 'select * from profiles where id = $1', [A])).toEqual([]);
  });
  it('B cannot read, add to, change or delete A’s data', async () => {
    const ha = await hh(A);
    const [m] = await as(A, `insert into medicines (household_id, name, dose_times, exp_month) values ($1,'Metformin 500',array['m','e'],'2027-03-01') returning id`, [ha]);
    expect(await as(B, 'select * from medicines')).toEqual([]);
    await fails(as(B, `insert into medicines (household_id, name) values ($1, 'x')`, [ha]), /row-level security/);
    expect(await as(B, `update medicines set name = 'hacked' where id = $1 returning id`, [m.id])).toEqual([]);
    expect(await as(B, `delete from medicines where id = $1 returning id`, [m.id])).toEqual([]);
    expect((await as(A, 'select name from medicines'))[0].name).toBe('Metformin 500');
  });
  it('B cannot attach a medicine or dose to A’s person or medicine from B’s own household', async () => {
    const [pa] = await as(A, 'select id from people');
    const [ma] = await as(A, 'select id from medicines');
    const hb = await hh(B);
    await fails(as(B, `insert into medicines (household_id, person_id, name) values ($1,$2,'x')`, [hb, pa.id]), /foreign key/);
    await fails(as(B, `insert into dose_logs (household_id, medicine_id, slot, taken_on) values ($1,$2,'m',current_date)`, [hb, ma.id]), /foreign key/);
  });
  it('B cannot move one of their rows into A’s household', async () => {
    const hb = await hh(B);
    const [mb] = await as(B, `insert into medicines (household_id, name) values ($1,'Paracetamol') returning id`, [hb]);
    await fails(as(B, `update medicines set household_id = $1 where id = $2`, [await hh(A), mb.id]), /row-level security/);
  });
  it('B cannot add themselves to A’s household', async () => {
    await fails(as(B, `insert into household_users (household_id, user_id, role) values ($1,$2,'owner')`, [await hh(A), B]), /row-level security/);
  });
  it('signed-out visitors see nothing private but can read medicine information', async () => {
    const info = await as(null, `select use_text from medicine_info where ingredient = 'Paracetamol'`);
    expect(info[0].use_text).toMatch(/fever/i);
    expect((await as(null, 'select count(*)::int n from medicine_info'))[0].n).toBeGreaterThan(50);
    await fails(as(null, `insert into medicine_info (ingredient, use_text) values ('x','y')`), /permission denied/);
    await fails(as(null, 'select * from people'), /permission denied/);
    await fails(as(null, 'select * from medicines'), /permission denied/);
  });
});

describe('roles inside a household', () => {
  it('a viewer can read but not change; a caregiver can change but not manage members', async () => {
    const ha = await hh(A);
    await as(A, `insert into household_users (household_id, user_id, role) values ($1,$2,'viewer'), ($1,$3,'caregiver')`, [ha, B, C]);
    expect((await as(B, 'select name from medicines where household_id = $1', [ha]))[0].name).toBe('Metformin 500');
    await fails(as(B, `insert into medicines (household_id, name) values ($1,'x')`, [ha]), /row-level security/);
    expect(await as(B, `update medicines set name = 'x' where household_id = $1 returning id`, [ha])).toEqual([]);
    const [mc] = await as(C, `insert into medicines (household_id, name) values ($1,'Cetirizine') returning id`, [ha]);
    expect(mc.id).toBeTruthy();
    expect(await as(C, `update household_users set role = 'owner' where household_id = $1 and user_id = $2 returning role`, [ha, C])).toEqual([]);
    expect(await as(C, `delete from households where id = $1 returning id`, [ha])).toEqual([]);
  });
  it('a member can leave a household; the owner can remove them', async () => {
    const ha = await hh(A);
    expect(await as(B, `delete from household_users where household_id = $1 and user_id = $2 returning user_id`, [ha, B])).toHaveLength(1);
    expect(await as(A, `delete from household_users where household_id = $1 and user_id = $2 returning user_id`, [ha, C])).toHaveLength(1);
    expect(await as(C, 'select * from medicines where household_id = $1', [ha])).toEqual([]);
  });
  it('only owners read the activity log', async () => {
    const ha = await hh(A);
    const log = await as(A, `select action, table_name from audit_log where household_id = $1 and table_name = 'medicines'`, [ha]);
    expect(log.length).toBeGreaterThanOrEqual(2);
    expect(await as(B, `select * from audit_log where household_id = $1`, [ha])).toEqual([]);
    await fails(as(A, `delete from audit_log`), /permission denied/);
  });
});

describe('data rules', () => {
  it('a child under 18 needs a guardian’s consent', async () => {
    const ha = await hh(A);
    await fails(as(A, `insert into people (household_id, name, birth_date) values ($1,'Kavi', current_date - 3000)`, [ha]), /guardian_consent_required/);
    const r = await as(A, `insert into people (household_id, name, birth_date, guardian_consent_at) values ($1,'Kavi', current_date - 3000, now()) returning id`, [ha]);
    expect(r).toHaveLength(1);
    expect(await as(A, `insert into people (household_id, name, birth_date) values ($1,'Paati','1950-02-01') returning id`, [ha])).toHaveLength(1);
  });
  it('rejects bad values', async () => {
    const ha = await hh(A);
    await fails(as(A, `insert into medicines (household_id, name, dose_times) values ($1,'x',array['night'])`, [ha]), /check constraint/);
    await fails(as(A, `insert into medicines (household_id, name) values ($1, repeat('x', 500))`, [ha]), /check constraint/);
    await fails(as(A, `insert into expenses (household_id, category, amount, spent_on) values ($1,'medicine',-5,current_date)`, [ha]), /check constraint/);
    await fails(as(A, `insert into people (household_id, name, blood_group) values ($1,'x','Z+')`, [ha]), /check constraint/);
  });
  it('one dose per medicine, time and day', async () => {
    const ha = await hh(A);
    const [m] = await as(A, `select id from medicines where name = 'Metformin 500'`);
    await as(A, `insert into dose_logs (household_id, medicine_id, slot, taken_on) values ($1,$2,'m','2026-11-16')`, [ha, m.id]);
    await fails(as(A, `insert into dose_logs (household_id, medicine_id, slot, taken_on) values ($1,$2,'m','2026-11-16')`, [ha, m.id]), /duplicate key/);
  });
  it('people cannot change protected profile fields', async () => {
    await fails(as(A, `update profiles set adult_confirmed_at = null`), /permission denied/);
    expect(await as(A, `update profiles set lang = 'ta' returning lang`)).toEqual([{ lang: 'ta' }]);
  });
});

describe('privacy rights', () => {
  it('a person can file a request but cannot mark it done or answer it', async () => {
    const [r] = await as(A, `insert into privacy_requests (kind, details) values ('access','Please send my data') returning id, status, due_on`);
    expect(r.status).toBe('open');
    await fails(as(A, `update privacy_requests set status = 'done' where id = $1`, [r.id]), /permission denied/);
    await fails(as(A, `insert into privacy_requests (kind, status) values ('erasure','done')`), /permission denied/);
    expect(await as(B, `select * from privacy_requests`)).toEqual([]);
  });
  it('consent can be recorded for oneself only', async () => {
    expect(await as(A, `insert into consent_records (purpose, granted) values ('marketing', true) returning purpose`)).toHaveLength(1);
    await fails(as(A, `insert into consent_records (user_id, purpose, granted) values ($1,'marketing', true)`, [B]), /permission denied/);
  });
  it('deleting an account removes the person and the household they created', async () => {
    const hb = await hh(B);
    await as(B, `select delete_my_account()`);
    const left = await db.query('select count(*)::int n from households where id = $1', [hb]);
    expect(left.rows[0]).toEqual({ n: 0 });
    expect((await db.query('select count(*)::int n from medicines where household_id = $1', [hb])).rows[0]).toEqual({ n: 0 });
    expect((await db.query('select count(*)::int n from profiles where id = $1', [B])).rows[0]).toEqual({ n: 0 });
    expect((await as(A, 'select count(*)::int n from medicines'))[0].n).toBeGreaterThan(0);
  });
});

describe('stock count', () => {
  it('a taken dose uses tablets and undoing it puts them back', async () => {
    const ha = await hh(A);
    const [m] = await as(A, `insert into medicines (household_id, name, dose_times, stock_count, per_dose) values ($1,'Telmisartan',array['e'],10,2) returning id`, [ha]);
    await as(A, `insert into dose_logs (household_id, medicine_id, slot, taken_on) values ($1,$2,'e','2026-11-16')`, [ha, m.id]);
    expect((await as(A, 'select stock_count from medicines where id = $1', [m.id]))[0].stock_count).toBe(8);
    await as(A, `delete from dose_logs where medicine_id = $1`, [m.id]);
    expect((await as(A, 'select stock_count from medicines where id = $1', [m.id]))[0].stock_count).toBe(10);
  });
});
