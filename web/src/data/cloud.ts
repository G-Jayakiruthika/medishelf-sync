// Supabase backend: real accounts. Row-level security in the database decides what each person can reach;
// this file only ever uses the public anon key.
import { createClient, type SupabaseClient } from '@supabase/supabase-js';
import { addDays, isoDay } from '../lib/dates';
import type {
  Appointment, ConsentPurpose, DoseLog, Expense, Household, Medicine, NewRow, Person, PrivacyRequest, Profile,
  RequestKind, Session, SignUpInput, Slot, Snapshot, Vital,
} from '../lib/types';
import type { Repo } from './repo';

export class CloudRepo implements Repo {
  readonly mode = 'cloud' as const;
  private sb: SupabaseClient;
  private householdId: string | null = null;

  constructor(url: string, anonKey: string) {
    this.sb = createClient(url, anonKey, { auth: { persistSession: true, autoRefreshToken: true, detectSessionInUrl: true, flowType: 'pkce' } });
  }

  private hh(): string {
    if (!this.householdId) throw new Error('not loaded');
    return this.householdId;
  }
  private static ok<T>(r: { data: T; error: { message: string } | null }): T {
    if (r.error) throw new Error(r.error.message);
    return r.data;
  }

  async session(): Promise<Session | null> {
    const { data } = await this.sb.auth.getSession();
    const u = data.session?.user;
    return u ? { userId: u.id, email: u.email ?? '' } : null;
  }
  onAuthChange(cb: (s: Session | null) => void) {
    const { data } = this.sb.auth.onAuthStateChange((_e, s) => cb(s?.user ? { userId: s.user.id, email: s.user.email ?? '' } : null));
    return () => data.subscription.unsubscribe();
  }
  async signUp(i: SignUpInput) {
    const r = await this.sb.auth.signUp({
      email: i.email.trim(), password: i.password,
      options: { data: { display_name: i.name.trim(), adult: i.adult, marketing: i.marketing }, emailRedirectTo: location.origin + import.meta.env.BASE_URL },
    });
    if (r.error) throw new Error(r.error.message);
    return { needsEmailConfirm: !r.data.session };
  }
  async signIn(email: string, password: string) {
    const r = await this.sb.auth.signInWithPassword({ email: email.trim(), password });
    if (r.error) throw new Error(r.error.message);
  }
  async sendReset(email: string) {
    const r = await this.sb.auth.resetPasswordForEmail(email.trim(), { redirectTo: location.origin + import.meta.env.BASE_URL + 'settings' });
    if (r.error) throw new Error(r.error.message);
  }
  async signOut() { await this.sb.auth.signOut(); this.householdId = null; }

  async load(): Promise<Snapshot> {
    const s = await this.session();
    if (!s) throw new Error('not signed in');
    const memberships = CloudRepo.ok(await this.sb.from('household_users').select('role, households(id, name)').eq('user_id', s.userId).order('created_at'));
    const first = (memberships as unknown as { role: Household['role']; households: { id: string; name: string } }[])[0];
    if (!first) throw new Error('no household');
    const household: Household = { id: first.households.id, name: first.households.name, role: first.role };
    this.householdId = household.id;
    const h = household.id;
    const since = addDays(isoDay(), -35);
    const [profile, people, medicines, doses, vitals, appointments, expenses, consents, requests] = await Promise.all([
      this.sb.from('profiles').select('*').eq('id', s.userId).single(),
      this.sb.from('people').select('*').eq('household_id', h).order('is_self', { ascending: false }).order('created_at'),
      this.sb.from('medicines').select('*').eq('household_id', h).order('name'),
      this.sb.from('dose_logs').select('*').eq('household_id', h).gte('taken_on', since),
      this.sb.from('vitals').select('*').eq('household_id', h).order('taken_at', { ascending: false }).limit(500),
      this.sb.from('appointments').select('*').eq('household_id', h).order('at'),
      this.sb.from('expenses').select('*').eq('household_id', h).order('spent_on', { ascending: false }).limit(1000),
      this.sb.from('consent_records').select('*').order('created_at', { ascending: false }),
      this.sb.from('privacy_requests').select('*').order('created_at', { ascending: false }),
    ]);
    return {
      profile: CloudRepo.ok(profile) as Profile,
      household,
      people: CloudRepo.ok(people) as Person[],
      medicines: CloudRepo.ok(medicines) as Medicine[],
      doses: CloudRepo.ok(doses) as DoseLog[],
      vitals: (CloudRepo.ok(vitals) as Vital[]).map(v => ({ ...v, v1: Number(v.v1), v2: v.v2 == null ? null : Number(v.v2) })),
      appointments: CloudRepo.ok(appointments) as Appointment[],
      expenses: (CloudRepo.ok(expenses) as Expense[]).map(e => ({ ...e, amount: Number(e.amount) })),
      consents: CloudRepo.ok(consents) as Snapshot['consents'],
      requests: CloudRepo.ok(requests) as PrivacyRequest[],
    };
  }

  // Only the listed columns are ever sent (no mass assignment of household_id, ids or timestamps from the form).
  private static medicineCols(m: NewRow<Medicine>) {
    const { person_id, name, strength, form, batch_no, exp_month, dose_times, per_dose, stock_count, course_end, food, notes } = m;
    return { person_id, name, strength, form, batch_no, exp_month, dose_times, per_dose, stock_count, course_end, food, notes };
  }
  async saveMedicine(m: NewRow<Medicine>) {
    const cols = CloudRepo.medicineCols(m);
    const q = m.id
      ? this.sb.from('medicines').update(cols).eq('id', m.id).select().single()
      : this.sb.from('medicines').insert({ ...cols, household_id: this.hh() }).select().single();
    return CloudRepo.ok(await q) as Medicine;
  }
  async deleteMedicine(id: string) { CloudRepo.ok(await this.sb.from('medicines').delete().eq('id', id)); }

  async setDose(medicine_id: string, slot: Slot, day: string, status: DoseLog['status'] | null, reason: string | null = null) {
    CloudRepo.ok(await this.sb.from('dose_logs').delete().match({ medicine_id, slot, taken_on: day }));
    if (status) CloudRepo.ok(await this.sb.from('dose_logs').insert({ household_id: this.hh(), medicine_id, slot, taken_on: day, status, reason }));
  }

  async savePerson(p: NewRow<Person>) {
    const { name, relation, birth_date, blood_group, allergies, conditions, emergency_contact, guardian_consent_at } = p;
    const cols = { name, relation, birth_date, blood_group, allergies, conditions, emergency_contact, guardian_consent_at };
    const q = p.id
      ? this.sb.from('people').update(cols).eq('id', p.id).select().single()
      : this.sb.from('people').insert({ ...cols, household_id: this.hh() }).select().single();
    return CloudRepo.ok(await q) as Person;
  }
  async deletePerson(id: string) { CloudRepo.ok(await this.sb.from('people').delete().eq('id', id)); }

  async addVital(v: NewRow<Vital>) {
    const { person_id, kind, v1, v2, context, note, taken_at } = v;
    CloudRepo.ok(await this.sb.from('vitals').insert({ person_id, kind, v1, v2, context, note, taken_at, household_id: this.hh() }));
  }
  async deleteVital(id: string) { CloudRepo.ok(await this.sb.from('vitals').delete().eq('id', id)); }
  async saveAppointment(a: NewRow<Appointment>) {
    const { person_id, doctor, place, at, questions } = a;
    const cols = { person_id, doctor, place, at, questions };
    CloudRepo.ok(await (a.id ? this.sb.from('appointments').update(cols).eq('id', a.id) : this.sb.from('appointments').insert({ ...cols, household_id: this.hh() })));
  }
  async deleteAppointment(id: string) { CloudRepo.ok(await this.sb.from('appointments').delete().eq('id', id)); }
  async addExpense(e: NewRow<Expense>) {
    const { person_id, category, amount, spent_on, note } = e;
    CloudRepo.ok(await this.sb.from('expenses').insert({ person_id, category, amount, spent_on, note, household_id: this.hh() }));
  }
  async deleteExpense(id: string) { CloudRepo.ok(await this.sb.from('expenses').delete().eq('id', id)); }

  async updateProfile(p: Partial<Pick<Profile, 'display_name' | 'lang' | 'marketing_ok' | 'weekly_email' | 'nominee_name' | 'nominee_contact'>>) {
    const s = await this.session();
    CloudRepo.ok(await this.sb.from('profiles').update({ ...p, updated_at: new Date().toISOString() }).eq('id', s!.userId));
  }
  async recordConsent(purpose: ConsentPurpose, granted: boolean) {
    CloudRepo.ok(await this.sb.from('consent_records').insert({ purpose, granted }));
  }
  async fileRequest(kind: RequestKind, details: string) {
    return CloudRepo.ok(await this.sb.from('privacy_requests').insert({ kind, details }).select().single()) as PrivacyRequest;
  }
  async deleteAccount() {
    CloudRepo.ok(await this.sb.rpc('delete_my_account'));
    await this.sb.auth.signOut();
  }
}
