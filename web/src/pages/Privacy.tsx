import { useState } from 'react';
import { Download, FileText, ShieldCheck, Trash2, UserCheck } from 'lucide-react';
import { useData, useStore } from '../data/store';
import { t } from '../lib/i18n';
import type { RequestKind } from '../lib/types';
import { Dialog } from '../components/ui';

// Records of processing (ISO/IEC 27701 style): what, why, legal basis under the DPDP Act 2023, and how long.
const REGISTER = [
  { data: 'Name, e-mail, password', why: 'Your account and sign-in', basis: 'Consent (s.6)', keep: 'Until you delete the account', note: 'Passwords are stored only as a salted hash by Supabase Auth.' },
  { data: 'Family members: name, relation, date of birth, blood group', why: 'Showing whose medicine is whose; vaccination age', basis: 'Consent (s.6); guardian consent for children (s.9)', keep: 'Until you remove the person or the account', note: '' },
  { data: 'Allergies, conditions, emergency contact', why: 'Emergency card and doctor visit sheet', basis: 'Consent (s.6)', keep: 'Until you remove them', note: 'Shown only to people in your household.' },
  { data: 'Medicines, expiry, stock, dose confirmations', why: 'Expiry alerts, today’s doses, refills', basis: 'Consent (s.6)', keep: 'Until you remove them; dose history 35 days on screen', note: '' },
  { data: 'Health readings (BP, sugar, temperature…)', why: 'Trends and plain-language checks', basis: 'Consent (s.6)', keep: 'Until you delete them', note: '' },
  { data: 'Doctor visits and medical expenses', why: 'Reminders, visit sheet, tax summary', basis: 'Consent (s.6)', keep: 'Until you delete them', note: '' },
  { data: 'Consent and request records', why: 'Proof of what you agreed to and when (s.6(10))', basis: 'Legal obligation', keep: 'As long as the account exists', note: '' },
  { data: 'Activity log (who changed what)', why: 'Security and your own audit trail', basis: 'Legitimate use (s.7)', keep: 'As long as the household exists', note: 'Owners of the household can read it.' },
];

const PRINCIPLES = [
  ['Consent and choice', 'Nothing optional is on without your agreement, and you can change your mind here.'],
  ['Purpose and minimisation', 'We ask only for what a feature needs. No Aadhaar, no location, no contacts.'],
  ['Use, retention and disclosure', 'Used only for AushadhBox. Never sold. No advertising trackers.'],
  ['Accuracy and quality', 'You can correct anything yourself, or ask us to.'],
  ['Openness and transparency', 'This page lists what we keep, why, and for how long.'],
  ['Individual participation', 'Download, correct, erase, withdraw consent, name a nominee.'],
  ['Accountability', 'Requests are answered within 30 days; grievances go to our Grievance Officer.'],
  ['Information security', 'Encrypted connections, per-household access rules in the database, and an activity log.'],
];

const KINDS: { k: RequestKind; label: string; hint: string }[] = [
  { k: 'access', label: 'A copy or summary of my data', hint: 'You can also download it yourself right now.' },
  { k: 'correction', label: 'Correct something', hint: 'Tell us what is wrong.' },
  { k: 'erasure', label: 'Erase specific data', hint: 'To erase everything, use “Delete my account” below.' },
  { k: 'withdraw', label: 'Withdraw a consent', hint: 'Say which consent.' },
  { k: 'grievance', label: 'Raise a grievance', hint: 'Tell us what happened.' },
];

export default function Privacy() {
  const d = useData();
  const { act, repo } = useStore();
  const [kind, setKind] = useState<RequestKind>('access');
  const [details, setDetails] = useState('');
  const [nominee, setNominee] = useState({ name: d.profile.nominee_name ?? '', contact: d.profile.nominee_contact ?? '' });
  const [deleting, setDeleting] = useState(false);
  const [confirm, setConfirm] = useState('');

  async function toggle(purpose: 'marketing' | 'weekly_email', on: boolean) {
    await act(async r => {
      await r.updateProfile(purpose === 'marketing' ? { marketing_ok: on } : { weekly_email: on });
      await r.recordConsent(purpose, on);
    }, on ? 'Turned on' : 'Turned off');
  }

  function download() {
    const { profile, household, people, medicines, doses, vitals, appointments, expenses, consents, requests } = d;
    const blob = new Blob([JSON.stringify({ exported_at: new Date().toISOString(), profile, household, people, medicines, doses, vitals, appointments, expenses, consents, requests }, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    Object.assign(document.createElement('a'), { href: url, download: 'aushadhbox-my-data.json' }).click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="page">
      <div className="page-head">
        <div><h1>{t('privacy')}</h1><p>Your rights under India’s Digital Personal Data Protection Act 2023, designed around the ISO/IEC 29100 privacy principles.</p></div>
        <button className="btn" onClick={download}><Download size={18} aria-hidden /> Download my data</button>
      </div>

      <div className="grid cols-2">
        <section className="card" aria-labelledby="h-choices">
          <h2 id="h-choices">Your choices</h2>
          <div className="stack">
            <label className="check"><input type="checkbox" checked={d.profile.weekly_email} onChange={e => toggle('weekly_email', e.target.checked)} />
              <span><b>Weekly summary e-mail</b><br /><span className="small muted">Expiring medicines and missed doses. Every e-mail has a one-click unsubscribe link.</span></span></label>
            <label className="check"><input type="checkbox" checked={d.profile.marketing_ok} onChange={e => toggle('marketing', e.target.checked)} />
              <span><b>Health tips and offers</b><br /><span className="small muted">Off unless you turn it on.</span></span></label>
          </div>
        </section>

        <section className="card" aria-labelledby="h-nominee">
          <h2 id="h-nominee"><span className="row"><UserCheck size={18} aria-hidden /> Nominee</span></h2>
          <p className="small muted" style={{ marginBottom: 12 }}>Someone who can use your rights if you die or cannot act yourself (DPDP Act s.14).</p>
          <div className="form-grid">
            <label className="field"><span>{t('name')}</span><input value={nominee.name} onChange={e => setNominee({ ...nominee, name: e.target.value })} maxLength={80} /></label>
            <label className="field"><span>Phone or e-mail</span><input value={nominee.contact} onChange={e => setNominee({ ...nominee, contact: e.target.value })} maxLength={120} /></label>
          </div>
          <button className="btn sm" style={{ marginTop: 12 }} onClick={() => act(r => r.updateProfile({ nominee_name: nominee.name.trim() || null, nominee_contact: nominee.contact.trim() || null }), 'Nominee saved')}>{t('save')}</button>
        </section>
      </div>

      <section className="card" aria-labelledby="h-request">
        <h2 id="h-request">Make a request</h2>
        <div className="form-grid">
          <label className="field"><span>What do you need?</span>
            <select value={kind} onChange={e => setKind(e.target.value as RequestKind)}>{KINDS.map(k => <option key={k.k} value={k.k}>{k.label}</option>)}</select>
            <small>{KINDS.find(k => k.k === kind)!.hint}</small></label>
          <label className="field"><span>Details</span><textarea value={details} onChange={e => setDetails(e.target.value)} maxLength={2000} /></label>
        </div>
        <button className="btn primary" style={{ marginTop: 12 }} onClick={async () => { const r = await act(x => x.fileRequest(kind, details.trim()), 'Request sent. We reply within 30 days.'); if (r) setDetails(''); }}>
          <FileText size={16} aria-hidden /> Send request</button>
        {d.requests.length > 0 && (
          <table className="table" style={{ marginTop: 16 }}>
            <thead><tr><th>Request</th><th>Sent</th><th>Status</th><th>Reply due</th></tr></thead>
            <tbody>{d.requests.map(r => (
              <tr key={r.id}><td>{KINDS.find(k => k.k === r.kind)?.label}{r.reply && <><br /><span className="small muted">{r.reply}</span></>}</td>
                <td>{new Date(r.created_at).toLocaleDateString()}</td><td><span className={`badge ${r.status === 'done' ? 'ok' : 'ai'}`}>{r.status.replace('_', ' ')}</span></td><td>{r.due_on}</td></tr>
            ))}</tbody>
          </table>
        )}
      </section>

      <section className="card flush" aria-labelledby="h-register">
        <h2 id="h-register">What we keep and why</h2>
        <div style={{ overflowX: 'auto', marginTop: 12 }}>
          <table className="table">
            <thead><tr><th>Data</th><th>Why</th><th>Legal basis</th><th>How long</th></tr></thead>
            <tbody>{REGISTER.map(r => <tr key={r.data}><td><b>{r.data}</b>{r.note && <><br /><span className="small muted">{r.note}</span></>}</td><td>{r.why}</td><td>{r.basis}</td><td>{r.keep}</td></tr>)}</tbody>
          </table>
        </div>
      </section>

      <div className="grid cols-2">
        <section className="card" aria-labelledby="h-principles">
          <h2 id="h-principles"><span className="row"><ShieldCheck size={18} aria-hidden /> How we handle data</span></h2>
          <dl className="kv" style={{ gridTemplateColumns: '1fr' }}>
            {PRINCIPLES.map(([h, p]) => <div key={h}><dt style={{ fontWeight: 700, color: 'var(--ink)' }}>{h}</dt><dd className="small muted">{p}</dd></div>)}
          </dl>
        </section>
        <section className="card" aria-labelledby="h-consents">
          <h2 id="h-consents">Consent history</h2>
          <ul className="list">
            {d.consents.slice(0, 12).map(c => (
              <li key={c.id}><span className="grow">{c.purpose.replace('_', ' ')}</span><span className={`badge ${c.granted ? 'ok' : ''}`}>{c.granted ? 'agreed' : 'declined'}</span>
                <span className="small muted">{new Date(c.created_at).toLocaleDateString()}</span></li>
            ))}
          </ul>
        </section>
      </div>

      <section className="card" aria-labelledby="h-delete" style={{ borderColor: 'color-mix(in srgb, var(--bad) 35%, var(--line))' }}>
        <h2 id="h-delete">Delete my account</h2>
        <p className="muted">Removes your account, the household you created and everything in it, straight away. Download your data first if you want a copy.</p>
        <button className="btn danger" style={{ marginTop: 12 }} onClick={() => setDeleting(true)}><Trash2 size={16} aria-hidden /> Delete my account</button>
      </section>

      <Dialog open={deleting} title="Delete everything?" onClose={() => { setDeleting(false); setConfirm(''); }}
        footer={<><button className="btn" onClick={() => setDeleting(false)}>{t('cancel')}</button>
          <button className="btn danger" disabled={confirm !== 'DELETE'} onClick={() => act(r => r.deleteAccount())}><Trash2 size={16} /> Delete for ever</button></>}>
        <p>This cannot be undone{repo.mode === 'demo' ? ' (in the demo it clears the sample family from this browser)' : ''}. Type <b>DELETE</b> to confirm.</p>
        <input value={confirm} onChange={e => setConfirm(e.target.value)} aria-label="Type DELETE to confirm" style={{ marginTop: 12 }} autoComplete="off" />
      </Dialog>
    </div>
  );
}
