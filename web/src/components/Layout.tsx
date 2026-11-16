import { NavLink, Outlet } from 'react-router-dom';
import { CalendarDays, Ellipsis, HeartPulse, LayoutDashboard, LogOut, Pill, Settings, ShieldCheck, Stethoscope, Users, Wallet } from 'lucide-react';
import { useStore, useData } from '../data/store';
import { t, type Key } from '../lib/i18n';
import { initials } from './ui';

const NAV: { to: string; key: Key; icon: typeof Pill }[] = [
  { to: '/', key: 'today', icon: LayoutDashboard },
  { to: '/cabinet', key: 'cabinet', icon: Pill },
  { to: '/family', key: 'family', icon: Users },
  { to: '/health', key: 'health', icon: HeartPulse },
  { to: '/visits', key: 'visits', icon: Stethoscope },
  { to: '/expenses', key: 'expenses', icon: Wallet },
  { to: '/privacy', key: 'privacy', icon: ShieldCheck },
  { to: '/settings', key: 'settings', icon: Settings },
];
// phones: the four most-used screens plus "More"
const TABS: { to: string; key: Key; icon: typeof Pill }[] = [
  NAV[0], NAV[1], NAV[2], NAV[3], { to: '/more', key: 'more', icon: Ellipsis },
];

function Brand() {
  return (
    <NavLink to="/" className="brand" aria-label="AushadhBox home">
      <img src={import.meta.env.BASE_URL + 'logo.svg'} alt="" />
      <div><b>AushadhBox</b><small>Family medicine cabinet</small></div>
    </NavLink>
  );
}

export default function Layout() {
  const { repo, act } = useStore();
  const d = useData();
  return (
    <div className="shell">
      <a className="skip-link" href="#main">Skip to content</a>
      <aside className="side" aria-label="Sidebar">
        <Brand />
        <nav className="nav" aria-label="Main">
          {NAV.map(n => (
            <NavLink key={n.to} to={n.to} end={n.to === '/'}><n.icon size={20} aria-hidden /> <span>{t(n.key)}</span></NavLink>
          ))}
        </nav>
        <div className="side-foot">
          <div className="who" title={d.profile.display_name ?? ''}>
            <div className="avatar" aria-hidden>{initials(d.profile.display_name)}</div>
            <span>{d.household.name}{repo.mode === 'demo' ? ' · demo' : ''}</span>
          </div>
          <button className="btn ghost sm" onClick={() => act(r => r.signOut())}><LogOut size={16} aria-hidden /><span className="lang-label">{t('signOut')}</span></button>
        </div>
      </aside>
      <header className="topbar">
        <Brand />
        <NavLink to="/visits" className="icon-btn" aria-label={t('visits')}><CalendarDays size={20} /></NavLink>
      </header>
      <main className="main" id="main">
        <Outlet />
      </main>
      <nav className="tabs" aria-label="Main">
        {TABS.map(n => (
          <NavLink key={n.to} to={n.to} end={n.to === '/'}><n.icon size={22} aria-hidden />{t(n.key)}</NavLink>
        ))}
      </nav>
    </div>
  );
}

export function More() {
  const { act } = useStore();
  return (
    <div className="page">
      <div className="page-head"><h1>{t('more')}</h1></div>
      <div className="card flush">
        <ul className="list">
          {NAV.slice(4).map(n => (
            <li key={n.to}><NavLink to={n.to} className="row grow" style={{ textDecoration: 'none', color: 'inherit' }}>
              <span className="pill-icon"><n.icon size={20} aria-hidden /></span><b>{t(n.key)}</b></NavLink></li>
          ))}
          <li><button className="btn ghost" onClick={() => act(r => r.signOut())}><LogOut size={18} /> {t('signOut')}</button></li>
        </ul>
      </div>
    </div>
  );
}
