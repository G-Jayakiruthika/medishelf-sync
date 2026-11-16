// Demo backend: a sample family kept only in this browser (localStorage), so the app works with no server.
import { addDays, isoDay } from '../lib/dates';
import type {
  Appointment, ConsentRecord, SignUpInput, DoseLog, Expense, Medicine, NewRow, Person, PrivacyRequest, Profile, Session, Snapshot, Vital,
} from '../lib/types';
import { type Repo, UserError } from './repo';

const KEY = 'aushadhbox-demo-v2';
const HH = 'demo-household';

interface Store {
  session: Session | null;
  profile: Profile;
  people: Person[];
  medicines: Medicine[];
  doses: DoseLog[];
  vitals: Vital[];
  appointments: Appointment[];
  expenses: Expense[];
  consents: ConsentRecord[];
  requests: PrivacyRequest[];
}

const uid = () => (globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2) + Date.now().toString(36));
const month = (offset: number) => {
  const d = new Date();
  return isoDay(new Date(d.getFullYear(), d.getMonth() + offset, 1));
};

function sample(name: string, email: string): Store {
  const today = isoDay();
  const now = new Date().toISOString();
  const self: Person = { id: 'p-self', household_id: HH, name: name || 'Asha', relation: 'self', is_self: true, birth_date: '1990-06-12', blood_group: 'B+', allergies: 'Penicillin', conditions: null, emergency_contact: 'Ravi · 98450 12345', guardian_consent_at: null };
  const people: Person[] = [
    self,
    { id: 'p-ravi', household_id: HH, name: 'Ravi', relation: 'husband', is_self: false, birth_date: '1986-02-03', blood_group: 'O+', allergies: null, conditions: 'Type 2 diabetes', emergency_contact: null, guardian_consent_at: null },
    { id: 'p-paati', household_id: HH, name: 'Paati', relation: 'grandmother', is_self: false, birth_date: '1951-09-20', blood_group: 'A+', allergies: null, conditions: 'High blood pressure', emergency_contact: null, guardian_consent_at: null },
    { id: 'p-kavi', household_id: HH, name: 'Kavi', relation: 'son', is_self: false, birth_date: addDays(today, -8 * 365 - 40), blood_group: 'B+', allergies: 'Peanuts', conditions: null, emergency_contact: null, guardian_consent_at: now },
  ];
  const m = (o: Partial<Medicine> & Pick<Medicine, 'id' | 'name'>): Medicine => ({
    household_id: HH, person_id: null, strength: null, form: 'tablet', batch_no: null, exp_month: month(10), dose_times: [], per_dose: 1,
    stock_count: null, course_end: null, food: null, notes: null, ...o,
  });
  const medicines: Medicine[] = [
    m({ id: 'm-metformin', name: 'Metformin', strength: '500 mg', person_id: 'p-ravi', dose_times: ['m', 'e'], stock_count: 18, food: 'after', batch_no: 'GM24117', exp_month: month(9) }),
    m({ id: 'm-amlodipine', name: 'Amlodipine', strength: '5 mg', person_id: 'p-paati', dose_times: ['m'], stock_count: 26, exp_month: month(1), batch_no: 'AM2311' }),
    m({ id: 'm-telmisartan', name: 'Telmisartan', strength: '40 mg', person_id: 'p-paati', dose_times: ['e'], stock_count: 9, exp_month: month(14) }),
    m({ id: 'm-amox', name: 'Amoxicillin', strength: '250 mg/5 ml', form: 'syrup', person_id: 'p-kavi', dose_times: ['m', 'a', 'e'], course_end: addDays(today, 3), food: 'after', exp_month: month(6), notes: 'Shake well. Finish the full course.' }),
    m({ id: 'm-vitd', name: 'Vitamin D3', strength: '1000 IU', person_id: 'p-self', dose_times: ['b'], stock_count: 52, exp_month: month(20) }),
    m({ id: 'm-paracetamol', name: 'Paracetamol (Dolo)', strength: '650 mg', exp_month: month(-1), stock_count: 6, notes: 'For fever. Not more than 4 a day.' }),
    m({ id: 'm-cetirizine', name: 'Cetirizine', strength: '10 mg', exp_month: month(2) }),
    m({ id: 'm-ors', name: 'ORS sachets', form: 'powder', exp_month: month(30), stock_count: 8 }),
  ];
  const doses: DoseLog[] = [];
  for (let i = 1; i <= 14; i++) {
    const day = addDays(today, -i);
    for (const med of medicines) for (const slot of med.dose_times) {
      if (med.course_end && day > med.course_end) continue;
      if (med.id === 'm-amox' && i > 4) continue;
      const missed = (i * 7 + slot.charCodeAt(0) + med.id.length) % 9 === 0;
      if (!missed) doses.push({ id: uid(), household_id: HH, medicine_id: med.id, slot, taken_on: day, status: 'taken', reason: null, taken_at: `${day}T08:00:00Z` });
    }
  }
  for (const med of medicines) if (med.dose_times.includes('m') && med.id !== 'm-amlodipine')
    doses.push({ id: uid(), household_id: HH, medicine_id: med.id, slot: 'm', taken_on: today, status: 'taken', reason: null, taken_at: now });
  const at = (d: number, h: number) => { const x = new Date(); x.setDate(x.getDate() + d); x.setHours(h, 0, 0, 0); return x.toISOString(); };
  const vitals: Vital[] = [
    ...[142, 151, 138, 133, 128, 126].map((v, i) => ({ id: uid(), household_id: HH, person_id: 'p-ravi', kind: 'sugar' as const, v1: v, v2: null, context: 'fasting' as const, note: null, taken_at: at(-(6 - i) * 5, 7) })),
    ...[[148, 92], [144, 90], [139, 88], [136, 86], [131, 84]].map(([s, d], i) => ({ id: uid(), household_id: HH, person_id: 'p-paati', kind: 'bp' as const, v1: s, v2: d, context: null, note: null, taken_at: at(-(5 - i) * 6, 9) })),
    { id: uid(), household_id: HH, person_id: 'p-kavi', kind: 'temp', v1: 100.8, v2: null, context: null, note: 'Ear pain', taken_at: at(-4, 20) },
  ];
  const appointments: Appointment[] = [
    { id: uid(), household_id: HH, person_id: 'p-paati', doctor: 'Dr. Meenakshi (cardiologist)', place: 'Kauvery Hospital, Erode', at: at(3, 10), questions: 'Can the evening tablet be moved to the morning?' },
    { id: uid(), household_id: HH, person_id: 'p-ravi', doctor: 'Dr. Senthil (diabetologist)', place: 'City Diabetes Centre', at: at(17, 18), questions: 'HbA1c test due' },
  ];
  const expenses: Expense[] = [
    { id: uid(), household_id: HH, person_id: 'p-ravi', category: 'medicine', amount: 412, spent_on: addDays(today, -3), note: 'Sri Sai Pharmacy' },
    { id: uid(), household_id: HH, person_id: 'p-kavi', category: 'consult', amount: 500, spent_on: addDays(today, -4), note: 'ENT doctor' },
    { id: uid(), household_id: HH, person_id: 'p-paati', category: 'test', amount: 850, spent_on: addDays(today, -21), note: 'Lipid profile + ECG' },
    { id: uid(), household_id: HH, person_id: null, category: 'medicine', amount: 268.5, spent_on: addDays(today, -40), note: 'Monthly medicines' },
  ];
  const consents: ConsentRecord[] = (['terms', 'privacy', 'adult'] as const).map(p => ({ id: uid(), purpose: p, granted: true, version: '2026-11', created_at: now }));
  consents.push({ id: uid(), purpose: 'marketing', granted: false, version: '2026-11', created_at: now });
  return {
    session: { userId: 'demo-user', email },
    profile: { id: 'demo-user', display_name: name || 'Asha', lang: 'en', adult_confirmed_at: now, marketing_ok: false, weekly_email: true, nominee_name: null, nominee_contact: null },
    people, medicines, doses, vitals, appointments, expenses, consents, requests: [],
  };
}

let memory: Store | null = null;
function read(): Store | null {
  try {
    const raw = localStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as Store) : memory;
  } catch { return memory; }
}
function write(s: Store | null) {
  memory = s;
  try { s ? localStorage.setItem(KEY, JSON.stringify(s)) : localStorage.removeItem(KEY); } catch { /* private mode: keep it in memory */ }
}

export class DemoRepo implements Repo {
  readonly mode = 'demo' as const;
  private listeners = new Set<(s: Session | null) => void>();

  private store(): Store {
    const s = read();
    if (!s?.session) throw new UserError('Please start the demo first.');
    return s;
  }
  private save(mut: (s: Store) => void) {
    const s = this.store();
    mut(s);
    write(s);
  }
  private emit() { const s = read()?.session ?? null; this.listeners.forEach(cb => cb(s)); }

  async session() { return read()?.session ?? null; }
  onAuthChange(cb: (s: Session | null) => void) { this.listeners.add(cb); return () => { this.listeners.delete(cb); }; }

  async signUp(i: SignUpInput) {
    if (!i.adult) throw new UserError('You must be 18 or older to create an account.');
    write(sample(i.name.trim(), i.email.trim() || 'demo@aushadhbox.in'));
    this.emit();
    return { needsEmailConfirm: false };
  }
  async signIn(email: string, _password?: string) {
    const s = read();
    write(s ? { ...s, session: { userId: 'demo-user', email } } : sample('', email || 'demo@aushadhbox.in'));
    this.emit();
  }
  async sendReset() { /* nothing to send in the demo */ }
  async signOut() { const s = read(); if (s) write({ ...s, session: null }); this.emit(); }

  async load(): Promise<Snapshot> {
    const s = this.store();
    const since = addDays(isoDay(), -35);
    return {
      profile: s.profile,
      household: { id: HH, name: `${s.profile.display_name ?? 'My'}'s family`, role: 'owner' },
      people: s.people, medicines: s.medicines, doses: s.doses.filter(d => d.taken_on >= since),
      vitals: s.vitals, appointments: s.appointments, expenses: s.expenses, consents: s.consents, requests: s.requests,
    };
  }

  async saveMedicine(m: NewRow<Medicine>) {
    const row = { ...m, id: m.id ?? uid(), household_id: HH, updated_at: new Date().toISOString() } as Medicine;
    this.save(s => { const i = s.medicines.findIndex(x => x.id === row.id); i >= 0 ? (s.medicines[i] = row) : s.medicines.push(row); });
    return row;
  }
  async deleteMedicine(id: string) {
    this.save(s => { s.medicines = s.medicines.filter(x => x.id !== id); s.doses = s.doses.filter(d => d.medicine_id !== id); });
  }
  async setDose(medicine_id: string, slot: DoseLog['slot'], day: string, status: DoseLog['status'] | null, reason: string | null = null) {
    // same stock rule as the database trigger: a confirmed dose uses tablets, undoing it puts them back
    this.save(s => {
      const med = s.medicines.find(x => x.id === medicine_id);
      const old = s.doses.find(d => d.medicine_id === medicine_id && d.slot === slot && d.taken_on === day);
      if (old?.status === 'taken' && med && med.stock_count != null) med.stock_count += med.per_dose;
      s.doses = s.doses.filter(d => d !== old);
      if (status) {
        s.doses.push({ id: uid(), household_id: HH, medicine_id, slot, taken_on: day, status, reason, taken_at: new Date().toISOString() });
        if (status === 'taken' && med && med.stock_count != null) med.stock_count = Math.max(0, med.stock_count - med.per_dose);
      }
    });
  }

  async savePerson(p: NewRow<Person>) {
    if (p.birth_date && !p.guardian_consent_at) {
      const [y, mo, d] = p.birth_date.split('-').map(Number);
      const eighteen = new Date(y + 18, mo - 1, d);
      if (eighteen > new Date()) throw new Error('guardian_consent_required');
    }
    const row = { ...p, id: p.id ?? uid(), household_id: HH } as Person;
    this.save(s => { const i = s.people.findIndex(x => x.id === row.id); i >= 0 ? (s.people[i] = row) : s.people.push(row); });
    return row;
  }
  async deletePerson(id: string) {
    this.save(s => {
      s.people = s.people.filter(x => x.id !== id);
      s.medicines.forEach(m => { if (m.person_id === id) m.person_id = null; });
      s.vitals = s.vitals.filter(v => v.person_id !== id);
      s.appointments = s.appointments.filter(a => a.person_id !== id);
    });
  }

  async addVital(v: NewRow<Vital>) { this.save(s => { s.vitals.push({ ...v, id: uid(), household_id: HH } as Vital); }); }
  async deleteVital(id: string) { this.save(s => { s.vitals = s.vitals.filter(x => x.id !== id); }); }
  async saveAppointment(a: NewRow<Appointment>) {
    const row = { ...a, id: a.id ?? uid(), household_id: HH } as Appointment;
    this.save(s => { const i = s.appointments.findIndex(x => x.id === row.id); i >= 0 ? (s.appointments[i] = row) : s.appointments.push(row); });
  }
  async deleteAppointment(id: string) { this.save(s => { s.appointments = s.appointments.filter(x => x.id !== id); }); }
  async addExpense(e: NewRow<Expense>) { this.save(s => { s.expenses.push({ ...e, id: uid(), household_id: HH } as Expense); }); }
  async deleteExpense(id: string) { this.save(s => { s.expenses = s.expenses.filter(x => x.id !== id); }); }

  async updateProfile(p: Partial<Profile>) { this.save(s => { s.profile = { ...s.profile, ...p }; }); }
  async recordConsent(purpose: ConsentRecord['purpose'], granted: boolean) {
    this.save(s => { s.consents.unshift({ id: uid(), purpose, granted, version: '2026-11', created_at: new Date().toISOString() }); });
  }
  async fileRequest(kind: PrivacyRequest['kind'], details: string) {
    const r: PrivacyRequest = { id: uid(), kind, details, status: 'open', reply: null, due_on: addDays(isoDay(), 30), created_at: new Date().toISOString() };
    this.save(s => { s.requests.unshift(r); });
    return r;
  }
  async deleteAccount() { write(null); this.emit(); }
}
