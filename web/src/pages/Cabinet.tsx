import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Info, Pencil, Pill, Plus, Search, Trash2 } from 'lucide-react';
import { canEdit, useData, useStore } from '../data/store';
import { expiryState, monthLabel, parseExpiry, SLOTS, stockDays } from '../lib/dates';
import { t } from '../lib/i18n';
import type { Food, Medicine, MedicineForm, MedicineInfo, NewRow, Slot } from '../lib/types';
import { Dialog, Empty, ExpiryBadge, PersonSelect, personName } from '../components/ui';
import infoData from '../data/medicine_info.json';

const INFO = infoData as MedicineInfo[];
const FORMS: MedicineForm[] = ['tablet', 'capsule', 'syrup', 'drops', 'injection', 'cream', 'inhaler', 'powder', 'other'];
const FOODS: { v: Food | ''; label: string }[] = [
  { v: '', label: 'Not stated' }, { v: 'before', label: 'Before food' }, { v: 'after', label: 'After food' },
  { v: 'with', label: 'With food' }, { v: 'empty', label: 'Empty stomach' }, { v: 'any', label: 'Any time' },
];

export function findInfo(name: string): MedicineInfo | undefined {
  const n = name.toLowerCase();
  if (n.length < 3) return undefined;
  return INFO.find(i => i.keywords.split(',').some(k => k.trim().length > 2 && n.includes(k.trim())));
}

type Filter = 'all' | 'expired' | 'soon' | 'scheduled' | string;

export default function Cabinet() {
  const d = useData();
  const { act } = useStore();
  const [params, setParams] = useSearchParams();
  const [q, setQ] = useState('');
  const [filter, setFilter] = useState<Filter>('all');
  const [editing, setEditing] = useState<NewRow<Medicine> | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<Medicine | null>(null);
  const editable = canEdit(d);

  useEffect(() => {
    if (params.get('add') && editable) { setEditing(blank()); setParams({}, { replace: true }); }
  }, [params, setParams, editable]);

  const list = useMemo(() => {
    const term = q.trim().toLowerCase();
    return d.medicines
      .filter(m => !term || m.name.toLowerCase().includes(term) || (m.notes ?? '').toLowerCase().includes(term))
      .filter(m => filter === 'all' ? true
        : filter === 'expired' ? expiryState(m.exp_month) === 'expired'
        : filter === 'soon' ? expiryState(m.exp_month) === 'soon'
        : filter === 'scheduled' ? m.dose_times.length > 0
        : filter === 'family' ? m.person_id === null
        : m.person_id === filter)
      .sort((a, b) => order(a) - order(b) || a.name.localeCompare(b.name));
  }, [d.medicines, q, filter]);

  const counts = {
    expired: d.medicines.filter(m => expiryState(m.exp_month) === 'expired').length,
    soon: d.medicines.filter(m => expiryState(m.exp_month) === 'soon').length,
  };

  return (
    <div className="page">
      <div className="page-head">
        <div><h1>{t('cabinet')}</h1><p>{d.medicines.length} medicines · {counts.expired} expired · {counts.soon} expiring within 60 days</p></div>
        {editable && <button className="btn primary" onClick={() => setEditing(blank())}><Plus size={18} aria-hidden /> {t('addMedicine')}</button>}
      </div>

      <div className="card">
        <div className="row" style={{ marginBottom: 12 }}>
          <label className="field grow" style={{ minWidth: 220 }}>
            <span className="sr-only">{t('search')}</span>
            <div style={{ position: 'relative' }}>
              <Search size={18} style={{ position: 'absolute', left: 12, top: 12, color: 'var(--muted)' }} aria-hidden />
              <input type="search" placeholder="Search medicines" value={q} onChange={e => setQ(e.target.value)} style={{ paddingLeft: 38 }} />
            </div>
          </label>
        </div>
        <div className="chips" role="group" aria-label="Filter">
          {([['all', t('all')], ['expired', `${t('expired')} (${counts.expired})`], ['soon', `${t('expiringSoon')} (${counts.soon})`], ['scheduled', 'Taken daily'], ['family', t('wholeFamily')]] as const)
            .map(([k, label]) => <button key={k} className="chip" aria-pressed={filter === k} onClick={() => setFilter(k)}>{label}</button>)}
          {d.people.map(p => <button key={p.id} className="chip" aria-pressed={filter === p.id} onClick={() => setFilter(p.id)}>{p.name}</button>)}
        </div>
      </div>

      {list.length === 0 ? (
        <div className="card"><Empty icon={<Pill size={40} />} title={d.medicines.length ? 'No medicines match.' : 'The cabinet is empty.'}>
          {d.medicines.length ? 'Try another filter.' : 'Add the medicines you have at home to get expiry alerts and dose reminders.'}</Empty></div>
      ) : (
        <>
          <div className="card flush only-wide">
            <table className="table">
              <thead><tr><th>Medicine</th><th>{t('forWhom')}</th><th>{t('doseTimes')}</th><th>{t('stock')}</th><th>{t('expiry')}</th><th className="actions"><span className="sr-only">Actions</span></th></tr></thead>
              <tbody>
                {list.map(m => (
                  <tr key={m.id}>
                    <td><b>{m.name}</b> {m.strength && <span className="muted small">{m.strength}</span>}<br /><span className="small muted">{m.form ?? ''}{m.batch_no ? ` · batch ${m.batch_no}` : ''}</span></td>
                    <td>{personName(d.people, m.person_id)}</td>
                    <td>{m.dose_times.length ? <span className="chips" style={{ gap: 4 }}>{SLOTS.filter(s => m.dose_times.includes(s)).map(s => <span key={s} className="badge ai">{t(s)}</span>)}</span> : <span className="muted small">When needed</span>}</td>
                    <td className="mono">{m.stock_count ?? '—'}{stockDays(m) != null && <span className="muted small"> · {stockDays(m)} d</span>}</td>
                    <td><ExpiryBadge exp={m.exp_month} /></td>
                    <td className="actions">
                      {editable && <>
                        <button className="icon-btn" aria-label={`${t('edit')} ${m.name}`} onClick={() => setEditing(m)}><Pencil size={18} /></button>
                        <button className="icon-btn" aria-label={`${t('delete')} ${m.name}`} onClick={() => setConfirmDelete(m)}><Trash2 size={18} /></button>
                      </>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="only-phone stack">
            {list.map(m => (
              <button key={m.id} className="card" style={{ textAlign: 'left', cursor: editable ? 'pointer' : 'default' }} onClick={() => editable && setEditing(m)}>
                <div className="spread"><b className="ellipsis">{m.name} {m.strength && <span className="muted small">{m.strength}</span>}</b><ExpiryBadge exp={m.exp_month} /></div>
                <p className="small muted" style={{ marginTop: 6 }}>{personName(d.people, m.person_id)} · {m.dose_times.length ? SLOTS.filter(s => m.dose_times.includes(s)).map(s => t(s)).join(', ') : 'when needed'}{m.stock_count != null ? ` · ${m.stock_count} left` : ''}</p>
              </button>
            ))}
          </div>
        </>
      )}

      {editing && <MedicineDialog initial={editing} onClose={() => setEditing(null)}
        onSave={async m => { const ok = await act(r => r.saveMedicine(m), m.id ? 'Medicine updated' : 'Medicine added'); if (ok) setEditing(null); }}
        onDelete={editing.id ? () => { setConfirmDelete(editing as Medicine); setEditing(null); } : undefined} />}

      <Dialog open={!!confirmDelete} title="Remove medicine?" onClose={() => setConfirmDelete(null)}
        footer={<><button className="btn" onClick={() => setConfirmDelete(null)}>{t('cancel')}</button>
          <button className="btn danger" onClick={async () => { await act(r => r.deleteMedicine(confirmDelete!.id), 'Medicine removed'); setConfirmDelete(null); }}><Trash2 size={16} /> {t('delete')}</button></>}>
        <p>Remove <b>{confirmDelete?.name}</b> and its dose history from the cabinet? This cannot be undone.</p>
      </Dialog>
    </div>
  );

  function blank(): NewRow<Medicine> {
    return { person_id: null, name: '', strength: null, form: 'tablet', batch_no: null, exp_month: null, dose_times: [], per_dose: 1, stock_count: null, course_end: null, food: null, notes: null };
  }
}

function order(m: Medicine) {
  return { expired: 0, soon: 1, ok: 2, unknown: 3 }[expiryState(m.exp_month)];
}

function MedicineDialog({ initial, onClose, onSave, onDelete }: {
  initial: NewRow<Medicine>; onClose: () => void; onSave: (m: NewRow<Medicine>) => void; onDelete?: () => void;
}) {
  const d = useData();
  const [m, setM] = useState<NewRow<Medicine>>(initial);
  const [exp, setExp] = useState(initial.exp_month ? initial.exp_month.slice(0, 7) : '');
  const [err, setErr] = useState<string | null>(null);
  const info = findInfo(m.name);
  const set = <K extends keyof NewRow<Medicine>>(k: K, v: NewRow<Medicine>[K]) => setM(x => ({ ...x, [k]: v }));
  const toggle = (s: Slot) => set('dose_times', m.dose_times.includes(s) ? m.dose_times.filter(x => x !== s) : [...m.dose_times, s]);

  function submit() {
    setErr(null);
    if (!m.name.trim()) return setErr('Type the medicine name.');
    let exp_month: string | null = null;
    if (exp.trim()) {
      exp_month = /^\d{4}-\d{2}$/.test(exp) ? `${exp}-01` : parseExpiry(exp);
      if (!exp_month) return setErr('Type the expiry like it is on the strip, for example 03/27.');
    }
    onSave({ ...m, name: m.name.trim(), exp_month });
  }

  return (
    <Dialog open title={initial.id ? `${t('edit')}: ${initial.name}` : t('addMedicine')} onClose={onClose} wide
      footer={<>
        {onDelete && <button className="btn danger" style={{ marginRight: 'auto' }} onClick={onDelete}><Trash2 size={16} /> {t('delete')}</button>}
        <button className="btn" onClick={onClose}>{t('cancel')}</button>
        <button className="btn primary" onClick={submit}>{t('save')}</button>
      </>}>
      <div className="form-grid">
        <label className="field"><span>{t('name')}</span>
          <input value={m.name} onChange={e => set('name', e.target.value)} maxLength={120} list="med-names" placeholder="e.g. Dolo 650, Metformin" autoFocus />
          <datalist id="med-names">{INFO.map(i => <option key={i.ingredient} value={i.ingredient} />)}</datalist></label>
        <label className="field"><span>{t('strength')}</span><input value={m.strength ?? ''} onChange={e => set('strength', e.target.value || null)} maxLength={40} placeholder="500 mg" /></label>
        {info && (
          <div className="full banner info" role="note"><Info size={18} aria-hidden />
            <span><b>{info.ingredient}</b>: {info.use}.{info.note ? ` ${info.note}` : ''} {info.rx ? 'Needs a prescription.' : ''}</span></div>
        )}
        <label className="field"><span>{t('forWhom')}</span><PersonSelect people={d.people} value={m.person_id} onChange={v => set('person_id', v)} /></label>
        <label className="field"><span>{t('form')}</span>
          <select value={m.form ?? ''} onChange={e => set('form', (e.target.value || null) as MedicineForm | null)}>{FORMS.map(f => <option key={f} value={f}>{f[0].toUpperCase() + f.slice(1)}</option>)}</select></label>
        <label className="field"><span>{t('expiry')}</span>
          <input value={exp} onChange={e => setExp(e.target.value)} placeholder="03/27" inputMode="numeric" maxLength={16} aria-describedby="exp-help" />
          <small id="exp-help">{exp && (parseExpiry(exp) || /^\d{4}-\d{2}$/.test(exp)) ? `Good until the end of ${monthLabel(/^\d{4}-\d{2}$/.test(exp) ? exp + '-01' : parseExpiry(exp))}` : 'As printed on the strip: 03/27, MAR 2027'}</small></label>
        <label className="field"><span>{t('batch')}</span><input value={m.batch_no ?? ''} onChange={e => set('batch_no', e.target.value || null)} maxLength={40} /></label>
        <div className="field full" role="group" aria-labelledby="dose-times-label">
          <span id="dose-times-label">{t('doseTimes')}</span>
          <div className="chips">
            {SLOTS.map(s => <button type="button" key={s} className="chip" aria-pressed={m.dose_times.includes(s)} onClick={() => toggle(s)}>{t(s)}</button>)}
            {info?.times && !m.dose_times.length && <button type="button" className="btn ghost sm" onClick={() => set('dose_times', info.times.split(',').filter(Boolean) as Slot[])}>Usual: {info.times.split(',').map(s => t(s as Slot)).join(' + ')}</button>}
          </div>
          <small className="muted">Leave empty for “when needed” medicines like paracetamol.</small>
        </div>
        <label className="field"><span>{t('perDose')}</span><input type="number" min={1} max={20} value={m.per_dose} onChange={e => set('per_dose', Math.min(20, Math.max(1, Number(e.target.value) || 1)))} /></label>
        <label className="field"><span>{t('stock')}</span><input type="number" min={0} max={10000} value={m.stock_count ?? ''} placeholder="Not counting" onChange={e => set('stock_count', e.target.value === '' ? null : Math.min(10000, Math.max(0, Number(e.target.value))))} /></label>
        <label className="field"><span>{t('food')}</span>
          <select value={m.food ?? ''} onChange={e => set('food', (e.target.value || null) as Food | null)}>{FOODS.map(f => <option key={f.v} value={f.v}>{f.label}</option>)}</select></label>
        <label className="field"><span>{t('courseEnd')}</span><input type="date" value={m.course_end ?? ''} onChange={e => set('course_end', e.target.value || null)} />
          <small>For short courses like antibiotics; reminders stop after this day.</small></label>
        <label className="field full"><span>{t('notes')}</span><textarea value={m.notes ?? ''} onChange={e => set('notes', e.target.value || null)} maxLength={300} /></label>
      </div>
      {err && <p role="alert" className="banner" style={{ marginTop: 14, background: 'var(--bad-soft)', color: 'var(--bad)' }}>{err}</p>}
    </Dialog>
  );
}
