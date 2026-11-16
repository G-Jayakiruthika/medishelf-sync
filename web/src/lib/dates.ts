// Date rules for the cabinet. All "days" are local calendar days in the browser's time zone.
import type { Medicine, Slot, DoseLog } from './types';

export const SLOTS: Slot[] = ['m', 'a', 'e', 'b'];
/** Usual clock time for each dose time, used to order the day and to decide what is "due now". */
export const SLOT_HOUR: Record<Slot, number> = { m: 8, a: 13, e: 19, b: 22 };

export function isoDay(d: Date = new Date()): string {
  const y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'), day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

export function addDays(day: string, n: number): string {
  const [y, m, d] = day.split('-').map(Number);
  return isoDay(new Date(y, m - 1, d + n));
}

/** Days from `from` to `to` (both YYYY-MM-DD). */
export function daysBetween(from: string, to: string): number {
  const [y1, m1, d1] = from.split('-').map(Number);
  const [y2, m2, d2] = to.split('-').map(Number);
  return Math.round((Date.UTC(y2, m2 - 1, d2) - Date.UTC(y1, m1 - 1, d1)) / 86400000);
}

/** Last usable day for an expiry month written as YYYY-MM-01 (medicines are good until the end of that month). */
export function lastUsableDay(expMonth: string): string {
  const [y, m] = expMonth.split('-').map(Number);
  return isoDay(new Date(y, m, 0));
}

export type ExpiryState = 'expired' | 'soon' | 'ok' | 'unknown';

/** expired: past the end of the month. soon: within 60 days. */
export function expiryState(expMonth: string | null, today: string = isoDay()): ExpiryState {
  if (!expMonth) return 'unknown';
  const left = daysBetween(today, lastUsableDay(expMonth));
  if (left < 0) return 'expired';
  if (left <= 60) return 'soon';
  return 'ok';
}

export function daysLeft(expMonth: string, today: string = isoDay()): number {
  return daysBetween(today, lastUsableDay(expMonth));
}

/** "2027-03-01" → "Mar 2027" (the way it is printed on a strip). */
export function monthLabel(expMonth: string | null, locale = 'en-IN'): string {
  if (!expMonth) return '—';
  const [y, m] = expMonth.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString(locale, { month: 'short', year: 'numeric' });
}

/** Accepts what people type from a strip: "03/27", "3/2027", "2027-03", "MAR 27", "Mar-2027". Returns YYYY-MM-01 or null. */
export function parseExpiry(input: string): string | null {
  const s = input.trim().toLowerCase().replace(/exp\.?|expiry|:/g, '').trim();
  if (!s) return null;
  const months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
  const year = (y: string) => (y.length === 2 ? 2000 + Number(y) : Number(y));
  let m: RegExpMatchArray | null;
  let mm = 0, yy = 0;
  if ((m = s.match(/^(\d{4})[-/.](\d{1,2})(?:[-/.]\d{1,2})?$/))) { yy = Number(m[1]); mm = Number(m[2]); }
  else if ((m = s.match(/^(\d{1,2})[-/. ](\d{2}|\d{4})$/))) { mm = Number(m[1]); yy = year(m[2]); }
  else if ((m = s.match(/^([a-z]{3})[a-z]*[-/. ]*(\d{2}|\d{4})$/))) { mm = months.indexOf(m[1]) + 1; yy = year(m[2]); }
  else return null;
  if (mm < 1 || mm > 12 || yy < 2000 || yy > 2100) return null;
  return `${yy}-${String(mm).padStart(2, '0')}-01`;
}

/** Is this medicine scheduled on `day`? (stops after a short course ends) */
export function scheduledOn(med: Medicine, day: string): boolean {
  if (!med.dose_times.length) return false;
  if (med.course_end && day > med.course_end) return false;
  if (med.exp_month && expiryState(med.exp_month, day) === 'expired') return false;
  return true;
}

export interface DoseSlot { medicine: Medicine; slot: Slot; log: DoseLog | undefined }

/** Today's doses in clock order. */
export function dosesFor(day: string, meds: Medicine[], logs: DoseLog[]): DoseSlot[] {
  const byKey = new Map(logs.filter(l => l.taken_on === day).map(l => [l.medicine_id + l.slot, l]));
  const out: DoseSlot[] = [];
  for (const slot of SLOTS) for (const med of meds) {
    if (scheduledOn(med, day) && med.dose_times.includes(slot)) out.push({ medicine: med, slot, log: byKey.get(med.id + slot) });
  }
  return out;
}

/** Share of scheduled doses confirmed as taken over the last `days` days, not counting today. null when nothing was due. */
export function adherence(meds: Medicine[], logs: DoseLog[], today: string = isoDay(), days = 7): number | null {
  let due = 0, taken = 0;
  const taken_set = new Set(logs.filter(l => l.status === 'taken').map(l => l.medicine_id + l.slot + l.taken_on));
  for (let i = 1; i <= days; i++) {
    const day = addDays(today, -i);
    for (const d of dosesFor(day, meds, [])) {
      due++;
      if (taken_set.has(d.medicine.id + d.slot + day)) taken++;
    }
  }
  return due ? Math.round((taken / due) * 100) : null;
}

/** Days of tablets left at the current schedule, or null when not counting. */
export function stockDays(med: Medicine): number | null {
  if (med.stock_count == null || !med.dose_times.length) return null;
  return Math.floor(med.stock_count / (med.per_dose * med.dose_times.length));
}

export function ageYears(birth: string, today: string = isoDay()): number {
  const [y, m, d] = birth.split('-').map(Number);
  const [ty, tm, td] = today.split('-').map(Number);
  return ty - y - (tm < m || (tm === m && td < d) ? 1 : 0);
}

/** India's financial year (April to March) that a day falls in, e.g. "2026-27". */
export function financialYear(day: string): string {
  const [y, m] = day.split('-').map(Number);
  const start = m >= 4 ? y : y - 1;
  return `${start}-${String((start + 1) % 100).padStart(2, '0')}`;
}
