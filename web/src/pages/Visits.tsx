import { useState } from 'react';
import { CalendarDays, MapPin, Pencil, Plus, Printer, Trash2 } from 'lucide-react';
import { canEdit, useData, useStore } from '../data/store';
import { t } from '../lib/i18n';
import { vitalText, VITAL_UNITS } from '../lib/health';
import type { Appointment, NewRow } from '../lib/types';
import { Dialog, Empty, PersonSelect, personName } from '../components/ui';

const local = (iso: string) => { const d = new Date(iso); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 16); };

export default function Visits() {
  const d = useData();
  const { act } = useStore();
  const [editing, setEditing] = useState<NewRow<Appointment> | null>(null);
  const [sheet, setSheet] = useState<Appointment | null>(null);
  const now = new Date().toISOString();
  const upcoming = d.appointments.filter(a => a.at >= now);
  const past = d.appointments.filter(a => a.at < now).reverse();
  const editable = canEdit(d);

  const Item = (a: Appointment) => (
    <li key={a.id}>
      <div className="pill-icon" aria-hidden style={{ flexDirection: 'column', lineHeight: 1, fontWeight: 800, width: 52, height: 52 }}>
        <span>{new Date(a.at).getDate()}</span><span style={{ fontSize: '.62rem', textTransform: 'uppercase' }}>{new Date(a.at).toLocaleDateString(undefined, { month: 'short' })}</span>
      </div>
      <span className="grow">
        <b className="ellipsis" style={{ display: 'block' }}>{a.doctor}</b>
        <span className="small muted">{personName(d.people, a.person_id)} · {new Date(a.at).toLocaleString(undefined, { weekday: 'short', hour: 'numeric', minute: '2-digit' })}{a.place ? <> · <MapPin size={12} aria-hidden /> {a.place}</> : null}</span>
        {a.questions && <span className="small" style={{ display: 'block', marginTop: 4 }}>Ask: {a.questions}</span>}
      </span>
      <button className="btn sm" onClick={() => setSheet(a)}><Printer size={15} aria-hidden /> <span className="only-wide">Visit sheet</span></button>
      {editable && <button className="icon-btn" aria-label={`${t('edit')} ${a.doctor}`} onClick={() => setEditing(a)}><Pencil size={17} /></button>}
      {editable && <button className="icon-btn" aria-label={`${t('delete')} ${a.doctor}`} onClick={() => act(r => r.deleteAppointment(a.id), 'Visit removed')}><Trash2 size={17} /></button>}
    </li>
  );

  return (
    <div className="page">
      <div className="page-head">
        <div><h1>{t('visits')}</h1><p>Plan visits, note what to ask, and print a one-page sheet for the doctor.</p></div>
        {editable && <button className="btn primary" onClick={() => setEditing({ person_id: d.people[0]?.id ?? null, doctor: '', place: null, at: new Date(Date.now() + 86400000).toISOString(), questions: null })}>
          <Plus size={18} aria-hidden /> Add visit</button>}
      </div>
      <div className="grid cols-2">
        <section className="card flush" aria-labelledby="h-up"><h2 id="h-up">{t('upcoming')}</h2>
          {upcoming.length ? <ul className="list">{upcoming.map(Item)}</ul> : <Empty icon={<CalendarDays size={36} />} title="No visits planned." />}</section>
        <section className="card flush" aria-labelledby="h-past"><h2 id="h-past">Past visits</h2>
          {past.length ? <ul className="list">{past.map(Item)}</ul> : <div style={{ padding: 18 }} className="muted">None yet.</div>}</section>
      </div>

      {editing && <VisitDialog initial={editing} onClose={() => setEditing(null)}
        onSave={async a => { await act(r => r.saveAppointment(a), 'Visit saved'); setEditing(null); }} />}

      <Dialog open={!!sheet} title="Visit sheet" onClose={() => setSheet(null)} wide
        footer={<><button className="btn" onClick={() => setSheet(null)}>Close</button><button className="btn primary" onClick={() => window.print()}><Printer size={16} /> Print</button></>}>
        {sheet && <VisitSheet a={sheet} />}
      </Dialog>
    </div>
  );
}

function VisitSheet({ a }: { a: Appointment }) {
  const d = useData();
  const p = d.people.find(x => x.id === a.person_id);
  const meds = d.medicines.filter(m => m.person_id === a.person_id && m.dose_times.length);
  const recent = d.vitals.filter(v => v.person_id === a.person_id).sort((x, y) => y.taken_at.localeCompare(x.taken_at)).slice(0, 8);
  return (
    <div className="stack">
      <div className="spread"><h2>{p?.name ?? 'Family'} · {a.doctor}</h2><span className="muted">{new Date(a.at).toLocaleDateString(undefined, { dateStyle: 'medium' })}</span></div>
      <dl className="kv"><dt>Allergies</dt><dd><b>{p?.allergies || 'None known'}</b></dd><dt>Conditions</dt><dd>{p?.conditions || 'None noted'}</dd></dl>
      <div><b>Current medicines</b><ul>{meds.length ? meds.map(m => <li key={m.id}>{m.name} {m.strength} — {m.per_dose} × {m.dose_times.map(s => ({ m: 'morning', a: 'afternoon', e: 'evening', b: 'bedtime' })[s]).join(', ')}</li>) : <li>None</li>}</ul></div>
      <div><b>Recent readings</b><ul>{recent.length ? recent.map(v => <li key={v.id}>{new Date(v.taken_at).toLocaleDateString()}: {v.kind.toUpperCase()} {vitalText(v)} {VITAL_UNITS[v.kind]}</li>) : <li>None</li>}</ul></div>
      {a.questions && <div><b>Questions to ask</b><p>{a.questions}</p></div>}
    </div>
  );
}

function VisitDialog({ initial, onClose, onSave }: { initial: NewRow<Appointment>; onClose: () => void; onSave: (a: NewRow<Appointment>) => void }) {
  const d = useData();
  const [a, setA] = useState(initial);
  const [when, setWhen] = useState(local(initial.at));
  const [err, setErr] = useState<string | null>(null);
  return (
    <Dialog open title={initial.id ? 'Edit visit' : 'Add a doctor visit'} onClose={onClose}
      footer={<><button className="btn" onClick={onClose}>{t('cancel')}</button>
        <button className="btn primary" onClick={() => {
          if (!a.doctor.trim()) return setErr('Type the doctor’s name.');
          if (!when) return setErr('Choose the date and time.');
          onSave({ ...a, doctor: a.doctor.trim(), at: new Date(when).toISOString() });
        }}>{t('save')}</button></>}>
      <div className="form-grid">
        <label className="field"><span>Doctor</span><input value={a.doctor} onChange={e => setA({ ...a, doctor: e.target.value })} maxLength={80} autoFocus /></label>
        <label className="field"><span>{t('forWhom')}</span><PersonSelect people={d.people} value={a.person_id} onChange={v => setA({ ...a, person_id: v })} /></label>
        <label className="field"><span>Date and time</span><input type="datetime-local" value={when} onChange={e => setWhen(e.target.value)} /></label>
        <label className="field"><span>Place</span><input value={a.place ?? ''} onChange={e => setA({ ...a, place: e.target.value || null })} maxLength={120} /></label>
        <label className="field full"><span>What to ask</span><textarea value={a.questions ?? ''} onChange={e => setA({ ...a, questions: e.target.value || null })} maxLength={500} /></label>
      </div>
      {err && <p role="alert" className="banner" style={{ marginTop: 14, background: 'var(--bad-soft)', color: 'var(--bad)' }}>{err}</p>}
    </Dialog>
  );
}
