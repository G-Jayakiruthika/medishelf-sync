// One interface, two backends: Supabase (real accounts) and a demo kept in this browser.
import type {
  Appointment, ConsentPurpose, DoseLog, Expense, Medicine, NewRow, Person, PrivacyRequest, Profile,
  RequestKind, Session, SignUpInput, Slot, Snapshot, Vital,
} from '../lib/types';

export type Mode = 'cloud' | 'demo';

export interface Repo {
  readonly mode: Mode;
  session(): Promise<Session | null>;
  onAuthChange(cb: (s: Session | null) => void): () => void;
  signUp(input: SignUpInput): Promise<{ needsEmailConfirm: boolean }>;
  signIn(email: string, password: string): Promise<void>;
  sendReset(email: string): Promise<void>;
  signOut(): Promise<void>;

  load(): Promise<Snapshot>;

  saveMedicine(m: NewRow<Medicine>): Promise<Medicine>;
  deleteMedicine(id: string): Promise<void>;
  /** status null removes the confirmation (back to "not yet"). */
  setDose(medicineId: string, slot: Slot, day: string, status: DoseLog['status'] | null, reason?: string | null): Promise<void>;

  savePerson(p: NewRow<Person>): Promise<Person>;
  deletePerson(id: string): Promise<void>;

  addVital(v: NewRow<Vital>): Promise<void>;
  deleteVital(id: string): Promise<void>;
  saveAppointment(a: NewRow<Appointment>): Promise<void>;
  deleteAppointment(id: string): Promise<void>;
  addExpense(e: NewRow<Expense>): Promise<void>;
  deleteExpense(id: string): Promise<void>;

  updateProfile(p: Partial<Pick<Profile, 'display_name' | 'lang' | 'marketing_ok' | 'weekly_email' | 'nominee_name' | 'nominee_contact'>>): Promise<void>;
  recordConsent(purpose: ConsentPurpose, granted: boolean): Promise<void>;
  fileRequest(kind: RequestKind, details: string): Promise<PrivacyRequest>;
  deleteAccount(): Promise<void>;
}

/** Errors shown to people: short, plain and without database details. */
export class UserError extends Error {}

export function friendly(e: unknown): string {
  const msg = e instanceof Error ? e.message : String(e);
  if (e instanceof UserError) return msg;
  if (/guardian_consent_required/.test(msg)) return 'A parent or guardian must agree before saving details of a child under 18.';
  if (/Invalid login credentials/i.test(msg)) return 'That e-mail and password do not match.';
  if (/Email not confirmed/i.test(msg)) return 'Please open the link we e-mailed you first.';
  if (/already registered|already been registered/i.test(msg)) return 'There is already an account with that e-mail. Try signing in.';
  if (/rate limit|too many/i.test(msg)) return 'Too many tries. Please wait a minute and try again.';
  if (/Failed to fetch|NetworkError|network/i.test(msg)) return 'No internet connection. Please try again.';
  if (/duplicate key/.test(msg)) return 'That is already saved.';
  if (/check constraint|violates/.test(msg)) return 'Some details are not valid. Please check and try again.';
  if (/row-level security|permission denied/.test(msg)) return 'You do not have permission to change this.';
  return 'Something went wrong. Please try again.';
}
