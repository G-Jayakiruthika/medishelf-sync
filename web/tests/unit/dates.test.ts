import { describe, expect, it } from 'vitest';
import { addDays, adherence, ageYears, daysBetween, dosesFor, expiryState, financialYear, lastUsableDay, parseExpiry, stockDays } from '../../src/lib/dates';
import { interactionWarnings, vitalFlag } from '../../src/lib/health';
import type { DoseLog, Medicine } from '../../src/lib/types';

const med = (o: Partial<Medicine>): Medicine => ({
  id: 'm1', household_id: 'h', person_id: null, name: 'X', strength: null, form: 'tablet', batch_no: null, exp_month: '2027-12-01',
  dose_times: ['m', 'e'], per_dose: 1, stock_count: null, course_end: null, food: null, notes: null, ...o,
});

describe('expiry', () => {
  it('a medicine is usable until the last day of its expiry month', () => {
    expect(lastUsableDay('2026-02-01')).toBe('2026-02-28');
    expect(lastUsableDay('2028-02-01')).toBe('2028-02-29');
    expect(expiryState('2026-11-01', '2026-11-30')).toBe('soon');
    expect(expiryState('2026-11-01', '2026-12-01')).toBe('expired');
    expect(expiryState('2027-06-01', '2026-11-16')).toBe('ok');
    expect(expiryState('2026-12-01', '2026-11-16')).toBe('soon');
    expect(expiryState(null)).toBe('unknown');
  });
  it('reads expiry the way it is printed on strips', () => {
    expect(parseExpiry('03/27')).toBe('2027-03-01');
    expect(parseExpiry('3/2027')).toBe('2027-03-01');
    expect(parseExpiry('2027-03')).toBe('2027-03-01');
    expect(parseExpiry('MAR 27')).toBe('2027-03-01');
    expect(parseExpiry('Exp: Mar-2027')).toBe('2027-03-01');
    expect(parseExpiry('13/27')).toBeNull();
    expect(parseExpiry('soon')).toBeNull();
  });
});

describe('days', () => {
  it('counts across months and leap years', () => {
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
    expect(daysBetween('2028-02-28', '2028-03-01')).toBe(2);
    expect(ageYears('2010-11-17', '2026-11-16')).toBe(15);
    expect(ageYears('2010-11-16', '2026-11-16')).toBe(16);
    expect(financialYear('2027-03-31')).toBe('2026-27');
    expect(financialYear('2027-04-01')).toBe('2027-28');
  });
});

describe('doses', () => {
  it('lists doses in clock order and stops after a course or expiry', () => {
    const a = med({ id: 'a', dose_times: ['e', 'm'] });
    const b = med({ id: 'b', dose_times: ['a'], course_end: '2026-11-10' });
    const c = med({ id: 'c', dose_times: ['m'], exp_month: '2026-10-01' });
    const list = dosesFor('2026-11-16', [a, b, c], []);
    expect(list.map(d => d.medicine.id + d.slot)).toEqual(['am', 'ae']);
  });
  it('adherence counts the last 7 full days', () => {
    const a = med({ id: 'a', dose_times: ['m'] });
    const logs: DoseLog[] = [1, 2, 3].map(i => ({ id: String(i), household_id: 'h', medicine_id: 'a', slot: 'm', taken_on: addDays('2026-11-16', -i), status: 'taken', reason: null, taken_at: '' }));
    expect(adherence([a], logs, '2026-11-16')).toBe(43);
    expect(adherence([], [], '2026-11-16')).toBeNull();
  });
  it('days of stock left', () => {
    expect(stockDays(med({ stock_count: 30, dose_times: ['m', 'e'], per_dose: 1 }))).toBe(15);
    expect(stockDays(med({ stock_count: null }))).toBeNull();
  });
});

describe('health checks', () => {
  it('flags readings', () => {
    expect(vitalFlag({ kind: 'bp', v1: 120, v2: 80, context: null })).toBe('ok');
    expect(vitalFlag({ kind: 'bp', v1: 150, v2: 95, context: null })).toBe('high');
    expect(vitalFlag({ kind: 'bp', v1: 185, v2: 100, context: null })).toBe('urgent');
    expect(vitalFlag({ kind: 'sugar', v1: 130, v2: null, context: 'fasting' })).toBe('high');
    expect(vitalFlag({ kind: 'sugar', v1: 130, v2: null, context: 'after_meal' })).toBe('ok');
    expect(vitalFlag({ kind: 'spo2', v1: 88, v2: null, context: null })).toBe('urgent');
  });
  it('warns about risky pairs', () => {
    expect(interactionWarnings(['Ecosprin (aspirin) 75', 'Warfarin 5'])).toHaveLength(1);
    expect(interactionWarnings(['Dolo (paracetamol) 650', 'Crocin paracetamol 500'])).toHaveLength(1);
    expect(interactionWarnings(['Metformin 500'])).toEqual([]);
  });
});
