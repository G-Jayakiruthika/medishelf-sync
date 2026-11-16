import { lazy, Suspense, useEffect } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { StoreProvider, useStore } from './data/store';
import Layout, { More } from './components/Layout';
import SignIn from './pages/SignIn';
import Today from './pages/Today';
import { setLang, t } from './lib/i18n';
import { applyTheme, savedTheme } from './lib/theme';

const Cabinet = lazy(() => import('./pages/Cabinet'));
const Family = lazy(() => import('./pages/Family'));
const Health = lazy(() => import('./pages/Health'));
const Visits = lazy(() => import('./pages/Visits'));
const Expenses = lazy(() => import('./pages/Expenses'));
const Privacy = lazy(() => import('./pages/Privacy'));
const Settings = lazy(() => import('./pages/Settings'));

function Toasts() {
  const { toasts } = useStore();
  return <div className="toasts" role="status" aria-live="polite">{toasts.map(x => <div key={x.id} className={`toast ${x.kind}`}>{x.text}</div>)}</div>;
}

function Loading({ text = 'Loading…' }: { text?: string }) {
  return <div style={{ minHeight: '60dvh', display: 'grid', placeItems: 'center' }} className="muted" aria-busy="true">{text}</div>;
}

function Gate() {
  const { ready, session, data, loadError, reload, repo } = useStore();
  useEffect(() => { if (data) setLang(data.profile.lang); }, [data]);
  if (!ready) return <Loading />;
  if (!session) return <SignIn />;
  if (loadError && !data) return <div className="page" style={{ padding: 40 }}><p role="alert" className="banner">{loadError}</p><button className="btn" style={{ marginTop: 12 }} onClick={reload}>Try again</button></div>;
  if (!data) return <Loading text="Opening your cabinet…" />;
  return (
    <>
      {repo.mode === 'demo' && <div className="sr-only" role="note">{t('demoBanner')}</div>}
      <Suspense fallback={<Loading />}>
        <Routes>
          <Route element={<Layout />}>
            <Route index element={<Today />} />
            <Route path="cabinet" element={<Cabinet />} />
            <Route path="family" element={<Family />} />
            <Route path="health" element={<Health />} />
            <Route path="visits" element={<Visits />} />
            <Route path="expenses" element={<Expenses />} />
            <Route path="privacy" element={<Privacy />} />
            <Route path="settings" element={<Settings />} />
            <Route path="more" element={<More />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      </Suspense>
    </>
  );
}

export default function App() {
  useEffect(() => { applyTheme(savedTheme()); }, []);
  return (
    <BrowserRouter basename={import.meta.env.BASE_URL.replace(/\/$/, '')}>
      <StoreProvider>
        <Gate />
        <Toasts />
      </StoreProvider>
    </BrowserRouter>
  );
}
