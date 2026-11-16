import { useMemo, useState } from 'react';
import { Activity, Plus, Trash2 } from 'lucide-react';
import { canEdit, useData, useStore } from '../data/store';
import { VITAL_UNITS, vitalFlag, vitalText, type Flag } from '../lib/health';
import { t } from '../lib/i18n';
import type { Vital, VitalKind } from '../lib/types';
import { Dialog, Empty } from '../components/ui';

const KINDS: { k: VitalKind; label: string }[] = [
  { k: 'bp', label: 'Blood pressure' }, { k: 'sugar', label: 'Blood sugar' }, { k: 'weight', label: 'Weight' },
  { k: 'temp', label: 'Temperature' }, { k: 'spo2', label: 'Oxygen (SpO₂)' }, { k: 'pulse', label: 'Pulse' },
];
const FLAG_TEXT: Record<Flag, string> = { ok: 'Normal range', low: 'Low', high: 'High', urgent: 'See a doctor today' };
const FLAG_CLASS: Record<Flag, string> = { ok: 'ok', low: 'warn', high: 'warn', urgent: 'bad' };

export default function Health() {
  const d = useData();
  const { act } = useStore();
  const [person, setPerson] = useState<string | null>(d.people.find(p => d.vitals.some(v => v.person_id === p.id))?.id ?? d.people[0]?.id ?? null);
  const [kind, setKind] = useState<VitalKind>(() => d.vitals.find(v => v.person_id === person)?.kind ?? 'bp');
  const [adding, setAdding] = useState(false);
  const editable = canEdit(d);

  const rows = useMemo(() => d.vitals.filter(v => v.person_id === person && v.kind === kind).sort((a, b) => b.taken_at.localeCompare(a.taken_at)), [d.vitals, person, kind]);
  const latest = KINDS.map(k => ({ ...k, v: d.vitals.filter(v => v.person_id === person && v.kind === k.k).sort((a, b) => b.taken_at.localeCompare(a.taken_at))[0] }));

  return (
    <div className="page">
      <div className="page-head">
        <div><h1>{t('health')}</h1><p>Readings with plain-language checks. These are general ranges, not a diagnosis.</p></div>
        {editable && <button className="btn primary" onClick={() => setAdding(true)}><Plus size={18} aria-hidden /> Add reading</button>}
      </div>

      <div className="chips" role="group" aria-label="Person">
        {d.people.map(p => <button key={p.id} className="chip" aria-pressed={person === p.id} onClick={() => setPerson(p.id)}>{p.name}</button>)}
      </div>

      <section className="grid cols-3" aria-label="Latest readings">
        {latest.map(({ k, label, v }) => {
          const f = v ? vitalFlag(v) : null;
          return (
            <button key={k} className="card stat" style={{ textAlign: 'left', cursor: 'pointer', outline: kind === k ? '2px solid var(--ai)' : undefined }} onClick={() => setKind(k)} aria-pressed={kind === k}>
              <span>{label}</span>
              <b className="mono" style={{ marginTop: 4 }}>{v ? vitalText(v) : '—'} <small className="muted" style={{ fontSize: '.8rem' }}>{v ? VITAL_UNITS[k] : ''}</small></b>
              {f ? <span className={`badge ${FLAG_CLASS[f]}`} style={{ marginTop: 6 }}>{FLAG_TEXT[f]}</span> : <span className="small muted">No readings</span>}
            </button>
          );
        })}
      </section>

      <div className="grid dash">
        <section className="card" aria-labelledby="h-trend">
          <h2 id="h-trend">{KINDS.find(k => k.k === kind)!.label} trend</h2>
          {rows.length < 2 ? <Empty icon={<Activity size={36} />} title="Add two or more readings to see a trend." /> : <Trend rows={[...rows].reverse().slice(-20)} />}
        </section>
        <section className="card flush" aria-labelledby="h-list">
          <h2 id="h-list">Readings</h2>
          {rows.length === 0 ? <div style={{ padding: 18 }} className="muted">None yet.</div> : (
            <ul className="list">
              {rows.slice(0, 30).map(v => {
                const f = vitalFlag(v);
                return (
                  <li key={v.id}>
                    <span className="grow"><b className="mono">{vitalText(v)}</b> <span className="muted small">{VITAL_UNITS[v.kind]}{v.context ? ` · ${v.context.replace('_', ' ')}` : ''}</span><br />
                      <span className="small muted">{new Date(v.taken_at).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}{v.note ? ` · ${v.note}` : ''}</span></span>
                    <span className={`badge ${FLAG_CLASS[f]}`}>{FLAG_TEXT[f]}</span>
                    {editable && <button className="icon-btn" aria-label="Delete reading" onClick={() => act(r => r.deleteVital(v.id))}><Trash2 size={16} /></button>}
                  </li>
                );
              })}
            </ul>
          )}
        </section>
      </div>

      {adding && <AddReading kind={kind} person={person} onClose={() => setAdding(false)}
        onSave={async v => { const ok = await act(r => r.addVital(v), 'Reading saved'); if (ok !== undefined) { setAdding(false); setKind(v.kind); setPerson(v.person_id); } }} />}
    </div>
  );
}

function Trend({ rows }: { rows: Vital[] }) {
  const W = 600, H = 160, P = 24;
  const vals = rows.flatMap(r => [r.v1, ...(r.v2 != null ? [r.v2] : [])]);
  const lo = Math.min(...vals) * 0.95, hi = Math.max(...vals) * 1.05;
  const x = (i: number) => P + (i * (W - 2 * P)) / Math.max(1, rows.length - 1);
  const y = (v: number) => H - P - ((v - lo) / (hi - lo || 1)) * (H - 2 * P);
  const line = (k: 'v1' | 'v2') => rows.map((r, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y((r[k] ?? r.v1) as number).toFixed(1)}`).join(' ');
  return (
    <svg className="spark" viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`Trend of ${rows.length} readings from ${vitalText(rows[0])} to ${vitalText(rows[rows.length - 1])}`} style={{ height: 200 }}>
      <path className="line" d={line('v1')} />
      {rows[0].kind === 'bp' && <path className="line" d={line('v2')} style={{ opacity: .5 }} />}
      {rows.map((r, i) => <circle key={r.id} className="dot" cx={x(i)} cy={y(r.v1)} r={4} />)}
      <text x={P} y={12}>{hi.toFixed(0)}</text><text x={P} y={H - 6}>{lo.toFixed(0)}</text>
    </svg>
  );
}

function AddReading({ kind: k0, person, onClose, onSave }: { kind: VitalKind; person: string | null; onClose: () => void; onSave: (v: Omit<Vital, 'id' | 'household_id'>) => void }) {
  const d = useData();
  const [kind, setKind] = useState(k0);
  const [who, setWho] = useState(person ?? d.people[0]?.id ?? null);
  const [v1, setV1] = useState('');
  const [v2, setV2] = useState('');
  const [context, setContext] = useState<Vital['context']>('fasting');
  const [note, setNote] = useState('');
  const [err, setErr] = useState<string | null>(null);
  const n1 = Number(v1), n2 = Number(v2);
  const preview = v1 && !Number.isNaN(n1) && n1 > 0 ? vitalFlag({ kind, v1: n1, v2: kind === 'bp' ? n2 : null, context: kind === 'sugar' ? context : null }) : null;

  function submit() {
    setErr(null);
    if (!(n1 > 0 && n1 < 2000) || (kind === 'bp' && !(n2 > 0 && n2 < 400))) return setErr('Please enter a valid reading.');
    onSave({ person_id: who, kind, v1: n1, v2: kind === 'bp' ? n2 : null, context: kind === 'sugar' ? context : null, note: note.trim() || null, taken_at: new Date().toISOString() });
  }

  return (
    <Dialog open title="Add a reading" onClose={onClose}
      footer={<><button className="btn" onClick={onClose}>{t('cancel')}</button><button className="btn primary" onClick={submit}>{t('save')}</button></>}>
      <div className="form-grid">
        <label className="field"><span>Person</span><select value={who ?? ''} onChange={e => setWho(e.target.value)}>{d.people.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
        <label className="field"><span>Reading</span><select value={kind} onChange={e => setKind(e.target.value as VitalKind)}>{KINDS.map(x => <option key={x.k} value={x.k}>{x.label}</option>)}</select></label>
        <label className="field"><span>{kind === 'bp' ? 'Upper (systolic)' : `Value (${VITAL_UNITS[kind]})`}</span><input inputMode="decimal" value={v1} onChange={e => setV1(e.target.value)} autoFocus /></label>
        {kind === 'bp' && <label className="field"><span>Lower (diastolic)</span><input inputMode="decimal" value={v2} onChange={e => setV2(e.target.value)} /></label>}
        {kind === 'sugar' && <label className="field"><span>When</span><select value={context ?? ''} onChange={e => setContext(e.target.value as Vital['context'])}>
          <option value="fasting">Fasting</option><option value="after_meal">2 hours after a meal</option><option value="random">Random</option></select></label>}
        <label className="field full"><span>{t('notes')}</span><input value={note} onChange={e => setNote(e.target.value)} maxLength={200} /></label>
      </div>
      {preview && <p className={`badge ${FLAG_CLASS[preview]}`} style={{ marginTop: 14 }}>{FLAG_TEXT[preview]}</p>}
      {err && <p role="alert" className="banner" style={{ marginTop: 14, background: 'var(--bad-soft)', color: 'var(--bad)' }}>{err}</p>}
    </Dialog>
  );
}
