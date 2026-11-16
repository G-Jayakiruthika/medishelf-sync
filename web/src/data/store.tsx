// App-wide state: which backend, who is signed in, and the loaded snapshot. Screens call `act()` to change data;
// it shows errors in a toast and reloads the snapshot afterwards.
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import type { Session, Snapshot } from '../lib/types';
import { CloudRepo } from './cloud';
import { DemoRepo } from './demo';
import { friendly, type Repo } from './repo';

const url = import.meta.env.VITE_SUPABASE_URL as string | undefined;
const key = import.meta.env.VITE_SUPABASE_ANON_KEY as string | undefined;
export const cloudAvailable = Boolean(url && key);

const cloud = cloudAvailable ? new CloudRepo(url!, key!) : null;
export const demo = new DemoRepo();

const MODE_KEY = 'aushadhbox-mode';
function savedMode(): 'cloud' | 'demo' {
  try { return (localStorage.getItem(MODE_KEY) as 'cloud' | 'demo') || (cloudAvailable ? 'cloud' : 'demo'); } catch { return cloudAvailable ? 'cloud' : 'demo'; }
}

interface Toast { id: number; text: string; kind: 'ok' | 'bad' }

interface Ctx {
  repo: Repo;
  session: Session | null;
  data: Snapshot | null;
  ready: boolean;
  loadError: string | null;
  useDemo(on: boolean): void;
  reload(): Promise<void>;
  act<T>(fn: (r: Repo) => Promise<T>, success?: string): Promise<T | undefined>;
  toast(text: string, kind?: Toast['kind']): void;
  toasts: Toast[];
}

const StoreCtx = createContext<Ctx | null>(null);

export function StoreProvider({ children }: { children: ReactNode }) {
  const [mode, setMode] = useState(savedMode);
  const repo: Repo = mode === 'cloud' && cloud ? cloud : demo;
  const [session, setSession] = useState<Session | null>(null);
  const [data, setData] = useState<Snapshot | null>(null);
  const [ready, setReady] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [toasts, setToasts] = useState<Toast[]>([]);

  const toast = useCallback((text: string, kind: Toast['kind'] = 'ok') => {
    const id = Date.now() + Math.random();
    setToasts(t => [...t, { id, text, kind }]);
    setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 4200);
  }, []);

  const reload = useCallback(async () => {
    try {
      setData(await repo.load());
      setLoadError(null);
    } catch (e) {
      setLoadError(friendly(e));
    }
  }, [repo]);

  useEffect(() => {
    let alive = true;
    setReady(false);
    setData(null);
    repo.session().then(s => { if (alive) { setSession(s); setReady(true); } });
    const off = repo.onAuthChange(s => { if (alive) setSession(s); });
    return () => { alive = false; off(); };
  }, [repo]);

  useEffect(() => {
    if (session) reload(); else setData(null);
  }, [session, reload]);

  const act = useCallback(async <T,>(fn: (r: Repo) => Promise<T>, success?: string) => {
    try {
      const out = await fn(repo);
      if (success) toast(success);
      if (session) await reload();
      return out;
    } catch (e) {
      toast(friendly(e), 'bad');
      return undefined;
    }
  }, [repo, session, reload, toast]);

  const useDemo = useCallback((on: boolean) => {
    const m = on || !cloud ? 'demo' : 'cloud';
    try { localStorage.setItem(MODE_KEY, m); } catch { /* ignore */ }
    setMode(m);
  }, []);

  const value = useMemo(() => ({ repo, session, data, ready, loadError, useDemo, reload, act, toast, toasts }),
    [repo, session, data, ready, loadError, useDemo, reload, act, toast, toasts]);
  return <StoreCtx.Provider value={value}>{children}</StoreCtx.Provider>;
}

export function useStore(): Ctx {
  const c = useContext(StoreCtx);
  if (!c) throw new Error('StoreProvider missing');
  return c;
}

/** For screens that only render once data is loaded. */
export function useData(): Snapshot {
  const { data } = useStore();
  if (!data) throw new Error('data not loaded');
  return data;
}

export function canEdit(d: Snapshot) { return d.household.role !== 'viewer'; }
