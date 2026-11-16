import { useState } from 'react';
import { Database, Globe, Monitor, Moon, Sun } from 'lucide-react';
import { cloudAvailable, useData, useStore } from '../data/store';
import { LANGS, setLang, t } from '../lib/i18n';
import type { Lang } from '../lib/types';
import { applyTheme, savedTheme, type Theme } from '../lib/theme';

export default function Settings() {
  const d = useData();
  const { act, repo, session, useDemo } = useStore();
  const [name, setName] = useState(d.profile.display_name ?? '');
  const [theme, setTheme] = useState<Theme>(savedTheme);

  return (
    <div className="page">
      <div className="page-head"><div><h1>{t('settings')}</h1><p>{session?.email}</p></div></div>
      <div className="grid cols-2">
        <section className="card stack" aria-labelledby="h-profile">
          <h2 id="h-profile">Profile</h2>
          <label className="field"><span>Your name</span><input value={name} maxLength={80} onChange={e => setName(e.target.value)} /></label>
          <button className="btn sm" style={{ alignSelf: 'flex-start' }} onClick={() => act(r => r.updateProfile({ display_name: name.trim() || null }), 'Saved')}>{t('save')}</button>
        </section>
        <section className="card stack" aria-labelledby="h-look">
          <h2 id="h-look"><span className="row"><Globe size={18} aria-hidden /> {t('language')} and look</span></h2>
          <div className="chips" role="group" aria-label={t('language')}>
            {LANGS.map(l => <button key={l.code} className="chip" aria-pressed={d.profile.lang === l.code} lang={l.code}
              onClick={() => { setLang(l.code as Lang); act(r => r.updateProfile({ lang: l.code })); }}>{l.label}</button>)}
          </div>
          <div className="chips" role="group" aria-label="Theme">
            {([['system', Monitor, 'Match device'], ['light', Sun, 'Light'], ['dark', Moon, 'Dark']] as const).map(([k, Icon, label]) => (
              <button key={k} className="chip" aria-pressed={theme === k} onClick={() => { setTheme(k); applyTheme(k); }}><Icon size={16} aria-hidden /> {label}</button>
            ))}
          </div>
        </section>
        <section className="card stack" aria-labelledby="h-data">
          <h2 id="h-data"><span className="row"><Database size={18} aria-hidden /> Where your data lives</span></h2>
          {repo.mode === 'demo' ? (
            <>
              <p>You are using the <b>demo</b>. The sample family is saved only in this browser.</p>
              {cloudAvailable && <button className="btn sm" style={{ alignSelf: 'flex-start' }} onClick={() => useDemo(false)}>Use a real account instead</button>}
            </>
          ) : <p>Your account is stored in AushadhBox’s database (Supabase, PostgreSQL), protected by per-household access rules.</p>}
        </section>
        <section className="card stack" aria-labelledby="h-household">
          <h2 id="h-household">Household</h2>
          <dl className="kv"><dt>Name</dt><dd>{d.household.name}</dd><dt>Your role</dt><dd>{d.household.role}</dd><dt>People</dt><dd>{d.people.length}</dd></dl>
        </section>
      </div>
    </div>
  );
}
