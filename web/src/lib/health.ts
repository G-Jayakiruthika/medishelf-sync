// Plain-language reading checks. These are general adult reference ranges, not a diagnosis.
import type { Vital, VitalKind } from './types';

export type Flag = 'ok' | 'low' | 'high' | 'urgent';

export const VITAL_UNITS: Record<VitalKind, string> = {
  bp: 'mmHg', sugar: 'mg/dL', weight: 'kg', temp: '°F', spo2: '%', pulse: 'bpm',
};

export function vitalFlag(v: Pick<Vital, 'kind' | 'v1' | 'v2' | 'context'>): Flag {
  const a = v.v1, b = v.v2 ?? 0;
  switch (v.kind) {
    case 'bp':
      if (a >= 180 || b >= 120) return 'urgent';
      if (a < 90 || b < 60) return 'low';
      if (a >= 140 || b >= 90) return 'high';
      return 'ok';
    case 'sugar':
      if (a < 54 || a >= 400) return 'urgent';
      if (a < 70) return 'low';
      if (v.context === 'fasting' ? a > 125 : a > 199) return 'high';
      return 'ok';
    case 'spo2':
      if (a < 90) return 'urgent';
      if (a < 95) return 'low';
      return 'ok';
    case 'temp':
      if (a >= 103 || a < 95) return 'urgent';
      if (a >= 100.4) return 'high';
      return 'ok';
    case 'pulse':
      if (a > 130 || a < 40) return 'urgent';
      if (a > 100) return 'high';
      if (a < 50) return 'low';
      return 'ok';
    default:
      return 'ok';
  }
}

export function vitalText(v: Pick<Vital, 'kind' | 'v1' | 'v2'>): string {
  const n = (x: number) => (Number.isInteger(x) ? String(x) : x.toFixed(1));
  return v.kind === 'bp' ? `${n(v.v1)}/${n(v.v2 ?? 0)}` : n(v.v1);
}

/** Basic interaction warnings between medicines in the same person's list (ingredient keywords, lower case). */
const PAIRS: [string, string, string][] = [
  ['warfarin', 'aspirin', 'Bleeding risk is higher when these are taken together.'],
  ['warfarin', 'ibuprofen', 'Bleeding risk is higher when these are taken together.'],
  ['aspirin', 'ibuprofen', 'Ibuprofen can reduce aspirin’s protection for the heart and upset the stomach.'],
  ['paracetamol', 'paracetamol', 'Two products both contain paracetamol. Too much can harm the liver.'],
  ['metformin', 'alcohol', 'Alcohol with metformin can cause low sugar.'],
  ['amlodipine', 'simvastatin', 'Keep simvastatin at 20 mg or less with amlodipine.'],
  ['clopidogrel', 'omeprazole', 'Omeprazole can make clopidogrel work less well; ask about pantoprazole.'],
  ['sildenafil', 'nitroglycerin', 'Dangerous drop in blood pressure.'],
];

export function interactionWarnings(names: string[]): string[] {
  const lower = names.map(n => n.toLowerCase());
  const out: string[] = [];
  for (const [x, y, why] of PAIRS) {
    const hasX = lower.filter(n => n.includes(x)).length;
    const hasY = lower.filter(n => n.includes(y)).length;
    if (x === y ? hasX >= 2 : hasX && hasY) out.push(why);
  }
  return out;
}
