-- Policy helpers live outside the API schema so they cannot be called as /rest/v1/rpc endpoints.
-- (Policies refer to functions by id, so they keep working after the move.)
create schema if not exists private;
revoke all on schema private from public, anon;
grant usage on schema private to authenticated;
alter function public.is_member(uuid, text[]) set schema private;
alter function public.can_edit(uuid) set schema private;
create or replace function private.can_edit(h uuid) returns boolean
language sql stable security definer set search_path = '' as $$
  select private.is_member(h, array['owner','caregiver'])
$$;
revoke execute on all functions in schema private from public, anon;
grant execute on function private.is_member(uuid, text[]), private.can_edit(uuid) to authenticated;
