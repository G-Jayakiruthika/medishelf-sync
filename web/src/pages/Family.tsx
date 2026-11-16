import { useState } from 'react';
import { Baby, IdCard, Pencil, Plus, Printer, Trash2, User } from 'lucide-react';
import { canEdit, useData, useStore } from '../data/store';
import { ageYears } from '../lib/dates';
import { t } from '../lib/i18n';
import type { NewRow, Person } from '../lib/types';
import { Dialog, initials } from '../components/ui';

const BLOOD = ['', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

export default function Family() {
  const d = useData();
  const { act } = useStore();
  const [editing, setEditing] = useState<NewRow<Person> | null>(null);
  const [card, setCard] = useState<Person | null>(null);
  const [remove, setRemove] = useState<Person | null>(null);
  const editable = canEdit(d);

  return (
    <div className="page">
      <div className="page-head">
        <div><h1>{t('family')}</h1><p>{d.people.length} people in {d.household.name}</p></div>
        {editable && <button className="btn primary" onClick={() => setEditing({ name: '', relation: null, is_self: false, birth_date: null, blood_group: null, allergies: null, conditions: null, emergency_contact: null, guardian_consent_at: null })}>
          <Plus size={18} aria-hidden /> {t('addPerson')}</button>}
      </div>

      <div className="grid cols-3">
        {d.people.map(p => {
          const age = p.birth_date ? ageYears(p.birth_date) : null;
          const meds = d.medicines.filter(m => m.person_id === p.id);
          return (
            <article key={p.id} className="card stack" aria-label={p.name}>
              <div className="row">
                <div className="avatar" style={{ width: 48, height: 48, fontSize: '1.05rem' }} aria-hidden>{initials(p.name)}</div>
                <div className="grow">
                  <h2>{p.name} {p.is_self && <span className="badge ai">You</span>}</h2>
                  <p className="small muted">{[p.relation && !p.is_self ? p.relation : null, age != null ? `${age} years` : null, p.blood_group].filter(Boolean).join(' · ') || 'No details yet'}</p>
                </div>
                {age != null && age < 18 && <Baby size={20} color="var(--ai)" aria-label="Child" />}
              </div>
              <dl className="kv">
                <dt>Allergies</dt><dd>{p.allergies || <span className="muted">None noted</span>}</dd>
                <dt>Conditions</dt><dd>{p.conditions || <span className="muted">None noted</span>}</dd>
                <dt>Medicines</dt><dd>{meds.length ? meds.map(m => m.name).join(', ') : <span className="muted">None</span>}</dd>
              </dl>
              <div className="row" style={{ marginTop: 'auto' }}>
                <button className="btn sm" onClick={() => setCard(p)}><IdCard size={16} aria-hidden /> Emergency card</button>
                {editable && <button className="btn sm ghost" onClick={() => setEditing(p)}><Pencil size={16} aria-hidden /> {t('edit')}</button>}
                {editable && !p.is_self && <button className="icon-btn" aria-label={`${t('delete')} ${p.name}`} onClick={() => setRemove(p)}><Trash2 size={18} /></button>}
              </div>
            </article>
          );
        })}
      </div>

      {editing && <PersonDialog initial={editing} onClose={() => setEditing(null)}
        onSave={async p => { const ok = await act(r => r.savePerson(p), p.id ? 'Saved' : `${p.name} added`); if (ok) setEditing(null); }} />}

      <Dialog open={!!card} title="Emergency card" onClose={() => setCard(null)}
        footer={<><button className="btn" onClick={() => setCard(null)}>Close</button><button className="btn primary" onClick={() => window.print()}><Printer size={16} /> Print</button></>}>
        {card && <EmergencyCard p={card} />}
      </Dialog>

      <Dialog open={!!remove} title={`Remove ${remove?.name}?`} onClose={() => setRemove(null)}
        footer={<><button className="btn" onClick={() => setRemove(null)}>{t('cancel')}</button>
          <button className="btn danger" onClick={async () => { await act(r => r.deletePerson(remove!.id), 'Removed'); setRemove(null); }}><Trash2 size={16} /> {t('delete')}</button></>}>
        <p>Their health readings and doctor visits are deleted. Their medicines stay in the cabinet as “whole family”.</p>
      </Dialog>
    </div>
  );
}

function EmergencyCard({ p }: { p: Person }) {
  const d = useData();
  const meds = d.medicines.filter(m => m.person_id === p.id && m.dose_times.length);
  return (
    <div className="card" style={{ borderWidth: 2, borderColor: 'var(--bad)' }}>
      <div className="spread"><h2 style={{ fontSize: '1.3rem' }}>{p.name}</h2><span className="badge bad">{p.blood_group ?? 'Blood group ?'}</span></div>
      <dl className="kv" style={{ marginTop: 12 }}>
        <dt>Age</dt><dd>{p.birth_date ? `${ageYears(p.birth_date)} years` : '—'}</dd>
        <dt>Allergies</dt><dd><b>{p.allergies || 'None known'}</b></dd>
        <dt>Conditions</dt><dd>{p.conditions || 'None known'}</dd>
        <dt>Daily medicines</dt><dd>{meds.length ? meds.map(m => `${m.name}${m.strength ? ' ' + m.strength : ''}`).join(', ') : 'None'}</dd>
        <dt>Call</dt><dd>{p.emergency_contact || '—'}</dd>
      </dl>
    </div>
  );
}

function PersonDialog({ initial, onClose, onSave }: { initial: NewRow<Person>; onClose: () => void; onSave: (p: NewRow<Person>) => void }) {
  const [p, setP] = useState(initial);
  const [guardian, setGuardian] = useState(Boolean(initial.guardian_consent_at));
  const [err, setErr] = useState<string | null>(null);
  const set = <K extends keyof NewRow<Person>>(k: K, v: NewRow<Person>[K]) => setP(x => ({ ...x, [k]: v }));
  const minor = p.birth_date ? ageYears(p.birth_date) < 18 : false;

  function submit() {
    setErr(null);
    if (!p.name.trim()) return setErr('Type a name.');
    if (minor && !guardian) return setErr('Please confirm you are the parent or guardian.');
    onSave({ ...p, name: p.name.trim(), guardian_consent_at: minor ? (p.guardian_consent_at ?? new Date().toISOString()) : p.guardian_consent_at });
  }

  return (
    <Dialog open title={initial.id ? `${t('edit')}: ${initial.name}` : t('addPerson')} onClose={onClose}
      footer={<><button className="btn" onClick={onClose}>{t('cancel')}</button><button className="btn primary" onClick={submit}>{t('save')}</button></>}>
      <div className="form-grid">
        <label className="field"><span>{t('name')}</span><input value={p.name} onChange={e => set('name', e.target.value)} maxLength={60} autoFocus /></label>
        <label className="field"><span>Relation</span><input value={p.is_self ? 'You' : p.relation ?? ''} disabled={p.is_self} onChange={e => set('relation', e.target.value || null)} maxLength={30} placeholder="mother, son, grandfather" /></label>
        <label className="field"><span>Date of birth</span><input type="date" value={p.birth_date ?? ''} max={new Date().toISOString().slice(0, 10)} onChange={e => set('birth_date', e.target.value || null)} /></label>
        <label className="field"><span>Blood group</span><select value={p.blood_group ?? ''} onChange={e => set('blood_group', e.target.value || null)}>{BLOOD.map(b => <option key={b} value={b}>{b || 'Not known'}</option>)}</select></label>
        <label className="field full"><span>Allergies</span><input value={p.allergies ?? ''} onChange={e => set('allergies', e.target.value || null)} maxLength={500} placeholder="Penicillin, peanuts" /></label>
        <label className="field full"><span>Long-term conditions</span><input value={p.conditions ?? ''} onChange={e => set('conditions', e.target.value || null)} maxLength={1600} placeholder="Diabetes, high BP" /></label>
        <label className="field full"><span>Emergency contact</span><input value={p.emergency_contact ?? ''} onChange={e => set('emergency_contact', e.target.value || null)} maxLength={400} placeholder="Name and phone number" /></label>
        {minor && (
          <label className="check full banner info" style={{ alignItems: 'flex-start' }}>
            <input type="checkbox" checked={guardian} onChange={e => setGuardian(e.target.checked)} />
            <span><User size={16} aria-hidden style={{ verticalAlign: '-3px' }} /> I am this child’s parent or legal guardian and agree to keep their health details here (DPDP Act 2023, section 9).</span>
          </label>
        )}
      </div>
      {err && <p role="alert" className="banner" style={{ marginTop: 14, background: 'var(--bad-soft)', color: 'var(--bad)' }}>{err}</p>}
    </Dialog>
  );
}
