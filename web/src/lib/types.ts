// Shapes shared by the screens and both data backends (demo and Supabase). Field names match the database columns.
export type Slot = 'm' | 'a' | 'e' | 'b';
export type Role = 'owner' | 'caregiver' | 'viewer';
export type Lang = 'en' | 'ta' | 'hi';

export interface Profile {
  id: string;
  display_name: string | null;
  lang: Lang;
  adult_confirmed_at: string | null;
  marketing_ok: boolean;
  weekly_email: boolean;
  nominee_name: string | null;
  nominee_contact: string | null;
}

export interface Household { id: string; name: string; role: Role }

export interface Person {
  id: string;
  household_id: string;
  name: string;
  relation: string | null;
  is_self: boolean;
  birth_date: string | null;
  blood_group: string | null;
  allergies: string | null;
  conditions: string | null;
  emergency_contact: string | null;
  guardian_consent_at: string | null;
}

export type MedicineForm = 'tablet' | 'capsule' | 'syrup' | 'drops' | 'injection' | 'cream' | 'inhaler' | 'powder' | 'other';
export type Food = 'before' | 'after' | 'with' | 'empty' | 'any';

export interface Medicine {
  id: string;
  household_id: string;
  person_id: string | null;
  name: string;
  strength: string | null;
  form: MedicineForm | null;
  batch_no: string | null;
  exp_month: string | null;   // YYYY-MM-01; usable until the end of that month
  dose_times: Slot[];
  per_dose: number;
  stock_count: number | null;
  course_end: string | null;
  food: Food | null;
  notes: string | null;
  updated_at?: string;
}

export interface DoseLog {
  id: string;
  household_id: string;
  medicine_id: string;
  slot: Slot;
  taken_on: string;
  status: 'taken' | 'skipped';
  reason: string | null;
  taken_at: string;
}

export type VitalKind = 'bp' | 'sugar' | 'weight' | 'temp' | 'spo2' | 'pulse';
export interface Vital {
  id: string;
  household_id: string;
  person_id: string | null;
  kind: VitalKind;
  v1: number;
  v2: number | null;
  context: 'fasting' | 'after_meal' | 'random' | null;
  note: string | null;
  taken_at: string;
}

export interface Appointment {
  id: string;
  household_id: string;
  person_id: string | null;
  doctor: string;
  place: string | null;
  at: string;
  questions: string | null;
}

export type ExpenseCategory = 'medicine' | 'test' | 'consult' | 'checkup' | 'other';
export interface Expense {
  id: string;
  household_id: string;
  person_id: string | null;
  category: ExpenseCategory;
  amount: number;
  spent_on: string;
  note: string | null;
}

export type ConsentPurpose = 'terms' | 'privacy' | 'adult' | 'marketing' | 'weekly_email' | 'ai' | 'guardian';
export interface ConsentRecord { id: string; purpose: ConsentPurpose; granted: boolean; version: string; created_at: string }

export type RequestKind = 'access' | 'correction' | 'erasure' | 'grievance' | 'withdraw';
export interface PrivacyRequest {
  id: string;
  kind: RequestKind;
  details: string | null;
  status: 'open' | 'in_progress' | 'done' | 'rejected';
  reply: string | null;
  due_on: string;
  created_at: string;
}

export interface MedicineInfo {
  ingredient: string;
  keywords: string;
  use: string;
  rx: number;
  storage: string;
  note: string;
  times: string;
  course_days: number | null;
  food: string;
}

/** Everything one screen might need, loaded together after sign-in. */
export interface Snapshot {
  profile: Profile;
  household: Household;
  people: Person[];
  medicines: Medicine[];
  doses: DoseLog[];          // the last 35 days
  vitals: Vital[];
  appointments: Appointment[];
  expenses: Expense[];
  consents: ConsentRecord[];
  requests: PrivacyRequest[];
}

export interface Session { userId: string; email: string }

export interface SignUpInput { email: string; password: string; name: string; adult: boolean; marketing: boolean }

export type NewRow<T> = Omit<T, 'id' | 'household_id' | 'updated_at'> & { id?: string };
