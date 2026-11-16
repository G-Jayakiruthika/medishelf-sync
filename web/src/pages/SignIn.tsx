import { useState, type FormEvent } from 'react';
import { BellRing, HeartPulse, Pill, ShieldCheck, Sparkles, Users } from 'lucide-react';
import { cloudAvailable, demo, useStore } from '../data/store';
import { friendly } from '../data/repo';

export default function SignIn() {
  const { repo, useDemo, toast } = useStore();
  const [tab, setTab] = useState<'in' | 'up'>('in');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [name, setName] = useState('');
  const [adult, setAdult] = useState(false);
  const [terms, setTerms] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const demoOnly = !cloudAvailable;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setNotice(null);
    if (tab === 'up') {
      if (password.length < 10) return setError('Use at least 10 characters for the password.');
      if (!adult) return setError('You must be 18 or older to create an account.');
      if (!terms) return setError('Please agree to the terms and privacy notice.');
    }
    setBusy(true);
    try {
      if (tab === 'in') await repo.signIn(email, password);
      else {
        const r = await repo.signUp({ email, password, name, adult, marketing });
        if (r.needsEmailConfirm) setNotice('Check your e-mail and open the link to finish creating your account.');
      }
    } catch (err) {
      setError(friendly(err));
    } finally {
      setBusy(false);
    }
  }

  async function startDemo() {
    if (!(await demo.session())) await demo.signIn('demo@aushadhbox.in', '');   // keeps an earlier demo family, or creates the sample one
    useDemo(true);
  }

  async function reset() {
    if (!email) return setError('Type your e-mail first, then tap “Forgot password”.');
    try { await repo.sendReset(email); toast('If that e-mail has an account, a reset link is on its way.'); }
    catch (err) { setError(friendly(err)); }
  }

  return (
    <div className="auth">
      <section className="auth-art">
        <div className="row" style={{ gap: 12 }}>
          <img src={import.meta.env.BASE_URL + 'logo.svg'} width={44} height={44} alt="" style={{ background: '#fff', borderRadius: 12, padding: 4 }} />
          <b style={{ fontSize: '1.15rem' }}>AushadhBox</b>
        </div>
        <div>
          <h1>Every medicine in your family, in one place.</h1>
          <p>Know what is in the cabinet, what expires soon, who has taken today’s tablets, and what to tell the doctor. Made for Indian families, in English, தமிழ் and हिन्दी.</p>
        </div>
        <ul>
          <li><Pill size={20} aria-hidden /> Expiry alerts and stock counts for every strip</li>
          <li><BellRing size={20} aria-hidden /> Today’s doses for everyone, with one tap to confirm</li>
          <li><HeartPulse size={20} aria-hidden /> Sugar, BP and temperature with plain-language checks</li>
          <li><Users size={20} aria-hidden /> Share with family members and caregivers</li>
          <li><ShieldCheck size={20} aria-hidden /> Your data stays yours: DPDP Act 2023 rights built in</li>
        </ul>
        <div className="ring" aria-hidden />
      </section>

      <section className="auth-form">
        <div className="card">
          {demoOnly ? (
            <div className="stack">
              <h2 style={{ fontSize: '1.3rem' }}>Try AushadhBox</h2>
              <p className="muted">This copy of the app runs as a demo with a sample family. Everything you change stays in this browser only.</p>
              <button className="btn primary" onClick={startDemo}><Sparkles size={18} aria-hidden /> Open the demo</button>
              <p className="small muted">Real accounts open once the app is connected to its database.</p>
            </div>
          ) : (
            <>
              <div className="seg" role="group" aria-label="Account">
                <button type="button" aria-pressed={tab === 'in'} onClick={() => setTab('in')}>Sign in</button>
                <button type="button" aria-pressed={tab === 'up'} onClick={() => setTab('up')}>Create account</button>
              </div>
              <form className="stack" onSubmit={submit} noValidate>
                {tab === 'up' && (
                  <label className="field"><span>Your name</span>
                    <input value={name} onChange={e => setName(e.target.value)} maxLength={60} autoComplete="name" required /></label>
                )}
                <label className="field"><span>E-mail</span>
                  <input type="email" value={email} onChange={e => setEmail(e.target.value)} autoComplete="email" required maxLength={254} /></label>
                <label className="field"><span>Password</span>
                  <input type="password" value={password} onChange={e => setPassword(e.target.value)} required minLength={tab === 'up' ? 10 : 1} maxLength={128}
                    autoComplete={tab === 'up' ? 'new-password' : 'current-password'} />
                  {tab === 'up' && <small>At least 10 characters. A short sentence is easy to remember.</small>}
                </label>
                {tab === 'up' && (
                  <>
                    <label className="check"><input type="checkbox" checked={adult} onChange={e => setAdult(e.target.checked)} /> I am 18 or older.</label>
                    <label className="check"><input type="checkbox" checked={terms} onChange={e => setTerms(e.target.checked)} />
                      <span>I agree to the <a href="#/terms" onClick={e => { e.preventDefault(); window.open(import.meta.env.BASE_URL + 'legal.html#terms', '_blank', 'noopener'); }}>terms</a> and the <a href="#/privacy" onClick={e => { e.preventDefault(); window.open(import.meta.env.BASE_URL + 'legal.html#privacy', '_blank', 'noopener'); }}>privacy notice</a>.</span></label>
                    <label className="check"><input type="checkbox" checked={marketing} onChange={e => setMarketing(e.target.checked)} /> Send me health tips and offers (optional, you can stop any time).</label>
                  </>
                )}
                {error && <p role="alert" className="banner" style={{ background: 'var(--bad-soft)', color: 'var(--bad)' }}>{error}</p>}
                {notice && <p role="status" className="banner info">{notice}</p>}
                <button className="btn primary" disabled={busy}>{busy ? 'Please wait…' : tab === 'in' ? 'Sign in' : 'Create account'}</button>
                {tab === 'in' && <button type="button" className="btn ghost sm" onClick={reset}>Forgot password</button>}
              </form>
              <hr style={{ border: 0, borderTop: '1px solid var(--line)', margin: '18px 0' }} />
              <button className="btn" style={{ width: '100%' }} onClick={startDemo}><Sparkles size={18} aria-hidden /> Look around with a sample family</button>
            </>
          )}
        </div>
      </section>
    </div>
  );
}
