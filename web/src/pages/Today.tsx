import { Link } from 'react-router-dom';
import { Check, Clock, Moon, Package, Pill, Sun, Sunrise, Sunset, TriangleAlert, Undo2 } from 'lucide-react';
import { canEdit, useData, useStore } from '../data/store';
import { adherence, dosesFor, expiryState, isoDay, SLOT_HOUR, SLOTS, stockDays, type DoseSlot } from '../lib/dates';
import { interactionWarnings } from '../lib/health';
import { t } from '../lib/i18n';
import type { Slot } from '../lib/types';
import { Empty, ExpiryBadge, personName } from '../components/ui';

const SLOT_ICON: Record<Slot, typeof Sun> = { m: Sunrise, a: Sun, e: Sunset, b: Moon };

function greeting() {
  const h = new Date().getHours();
  return h < 12 ? t('goodMorning') : h < 17 ? t('goodAfternoon') : t('goodEvening');
}

export default function Today() {
  const d = useData();
  const { act } = useStore();
  const today = isoDay();
  const doses = dosesFor(today, d.medicines, d.doses);
  const done = doses.filter(x => x.log).length;
  const rate = adherence(d.medicines, d.doses, today);
  const expired = d.medicines.filter(m => expiryState(m.exp_month) === 'expired');
  const soon = d.medicines.filter(m => expiryState(m.exp_month) === 'soon');
  const low = d.medicines.filter(m => { const s = stockDays(m); return s != null && s <= 7; });
  const next = d.appointments.filter(a => new Date(a.at) >= new Date()).slice(0, 3);
  const hour = new Date().getHours();
  const editable = canEdit(d);

  const warnings = d.people.flatMap(p => interactionWarnings(d.medicines.filter(m => m.person_id === p.id || m.person_id === null).filter(m => m.dose_times.length).map(m => m.name))
    .map(w => `${p.name}: ${w}`));

  const mark = (x: DoseSlot, status: 'taken' | 'skipped' | null) =>
    act(r => r.setDose(x.medicine.id, x.slot, today, status), status === 'taken' ? `${x.medicine.name} marked as taken` : undefined);

  return (
    <div className="page">
      <div className="page-head">
        <div>
          <h1>{greeting()}, {d.profile.display_name?.split(' ')[0] ?? 'there'}</h1>
          <p>{new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })} · {d.household.name}</p>
        </div>
        {editable && <Link className="btn primary" to="/cabinet?add=1"><Pill size={18} aria-hidden /> {t('addMedicine')}</Link>}
      </div>

      <section className="stats" aria-label="Summary">
        <div className="card stat"><b>{done}/{doses.length}</b><span>{t('dosesToday')}</span>
          <div className="progress" style={{ marginTop: 10 }}><i style={{ width: doses.length ? `${(done / doses.length) * 100}%` : 0 }} /></div></div>
        <div className={`card stat ${rate == null ? '' : rate >= 80 ? 'ok' : rate >= 60 ? 'warn' : 'bad'}`}><b>{rate == null ? '—' : `${rate}%`}</b><span>{t('adherence7')}</span></div>
        <div className={`card stat ${expired.length ? 'bad' : ''}`}><b>{expired.length}</b><span>{t('expired')} · {soon.length} {t('expiringSoon').toLowerCase()}</span></div>
        <div className={`card stat ${low.length ? 'warn' : ''}`}><b>{low.length}</b><span>{t('lowStock')} (≤ 7 days)</span></div>
      </section>

      {warnings.length > 0 && (
        <div className="banner" role="note"><TriangleAlert size={18} aria-hidden /> <span>{warnings[0]} Ask your doctor or pharmacist.{warnings.length > 1 ? ` (+${warnings.length - 1} more)` : ''}</span></div>
      )}

      <div className="grid dash">
        <section className="card" aria-labelledby="h-doses">
          <h2 id="h-doses">{t('dosesToday')} {doses.length > 0 && done === doses.length && <span className="badge ok"><Check size={14} /> {t('allDone')}</span>}</h2>
          {doses.length === 0 ? <Empty icon={<Clock size={36} />} title={t('nothingDue')}>Add when to take a medicine in the cabinet to see it here.</Empty> :
            SLOTS.map(slot => {
              const list = doses.filter(x => x.slot === slot);
              if (!list.length) return null;
              const Icon = SLOT_ICON[slot];
              const due = hour >= SLOT_HOUR[slot];
              return (
                <div className="slot-group" key={slot}>
                  <div className="slot-title"><Icon size={16} aria-hidden /> {t(slot)} <span className="muted">· {SLOT_HOUR[slot] > 12 ? SLOT_HOUR[slot] - 12 : SLOT_HOUR[slot]}{SLOT_HOUR[slot] >= 12 ? ' pm' : ' am'}</span></div>
                  {list.map(x => {
                    const st = x.log?.status;
                    return (
                      <div key={x.medicine.id + slot} className={`dose ${st === 'taken' ? 'done' : st === 'skipped' ? 'skipped' : ''}`}>
                        <span className="pill-icon" aria-hidden>{st === 'taken' ? <Check size={20} /> : <Pill size={20} />}</span>
                        <div className="grow">
                          <b className="ellipsis" style={{ display: 'block' }}>{x.medicine.name} {x.medicine.strength && <span className="muted small">{x.medicine.strength}</span>}</b>
                          <span className="who-tag">{personName(d.people, x.medicine.person_id)} · {x.medicine.per_dose} {x.medicine.form === 'syrup' ? '× 5 ml' : x.medicine.per_dose > 1 ? 'tablets' : 'tablet'}
                            {x.medicine.food && x.medicine.food !== 'any' ? ` · ${x.medicine.food} food` : ''}</span>
                        </div>
                        {st ? (
                          <>
                            <span className={`badge ${st === 'taken' ? 'ok' : ''}`}>{t(st)}</span>
                            {editable && <button className="icon-btn" aria-label={`${t('undo')}: ${x.medicine.name}`} onClick={() => mark(x, null)}><Undo2 size={18} /></button>}
                          </>
                        ) : editable ? (
                          <div className="row dose-actions" style={{ gap: 6 }}>
                            {!due && <span className="badge only-wide">{t('notYet')}</span>}
                            <button className="btn sm" onClick={() => mark(x, 'skipped')}>{t('skip')}</button>
                            <button className="btn sm primary" onClick={() => mark(x, 'taken')} aria-label={`${t('take')}: ${x.medicine.name} for ${personName(d.people, x.medicine.person_id)}`}><Check size={16} aria-hidden /> {t('take')}</button>
                          </div>
                        ) : <span className="badge">{t('notYet')}</span>}
                      </div>
                    );
                  })}
                </div>
              );
            })}
        </section>

        <div className="stack" style={{ gap: 16 }}>
          <section className="card" aria-labelledby="h-attn">
            <h2 id="h-attn">Needs attention</h2>
            {expired.length + soon.length + low.length === 0 ? <p className="muted">Nothing expired, nothing running low.</p> : (
              <ul className="list">
                {[...expired, ...soon].slice(0, 6).map(m => (
                  <li key={'e' + m.id}><TriangleAlert size={18} color={expiryState(m.exp_month) === 'expired' ? 'var(--bad)' : 'var(--warn)'} aria-hidden />
                    <span className="grow ellipsis">{m.name}<br /><span className="small muted">{personName(d.people, m.person_id)}</span></span><ExpiryBadge exp={m.exp_month} /></li>
                ))}
                {low.map(m => (
                  <li key={'s' + m.id}><Package size={18} color="var(--warn)" aria-hidden />
                    <span className="grow ellipsis">{m.name}<br /><span className="small muted">{m.stock_count} left</span></span><span className="badge warn">{stockDays(m)} days</span></li>
                ))}
              </ul>
            )}
            <Link to="/cabinet" className="btn sm" style={{ marginTop: 12 }}>Open the cabinet</Link>
          </section>

          <section className="card" aria-labelledby="h-next">
            <h2 id="h-next">{t('upcoming')}</h2>
            {next.length === 0 ? <p className="muted">No doctor visits planned.</p> : (
              <ul className="list">
                {next.map(a => (
                  <li key={a.id}>
                    <div className="pill-icon" aria-hidden style={{ flexDirection: 'column', lineHeight: 1, fontWeight: 800 }}>
                      <span style={{ fontSize: '.95rem' }}>{new Date(a.at).getDate()}</span>
                      <span style={{ fontSize: '.62rem', textTransform: 'uppercase' }}>{new Date(a.at).toLocaleDateString(undefined, { month: 'short' })}</span>
                    </div>
                    <span className="grow"><b className="ellipsis" style={{ display: 'block' }}>{a.doctor}</b>
                      <span className="small muted">{personName(d.people, a.person_id)} · {new Date(a.at).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}</span></span>
                  </li>
                ))}
              </ul>
            )}
            <Link to="/visits" className="btn sm" style={{ marginTop: 12 }}>All visits</Link>
          </section>
        </div>
      </div>
    </div>
  );
}
