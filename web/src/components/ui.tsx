import { useEffect, useRef, type ReactNode } from 'react';
import { X } from 'lucide-react';
import { expiryState, daysLeft, monthLabel } from '../lib/dates';
import type { Person } from '../lib/types';

/** Native <dialog>: focus trap, Escape to close and an accessible modal for free. */
export function Dialog({ open, title, onClose, children, footer, wide }: {
  open: boolean; title: string; onClose: () => void; children: ReactNode; footer?: ReactNode; wide?: boolean;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const d = ref.current;
    if (!d) return;
    if (open && !d.open) d.showModal();
    if (!open && d.open) d.close();
  }, [open]);
  return (
    <dialog ref={ref} onClose={onClose} aria-labelledby="dlg-title" className={wide ? 'wide' : undefined}
      onClick={e => { if (e.target === ref.current) onClose(); }}>
      {open && (
        <>
          <div className="dlg-head">
            <h2 id="dlg-title">{title}</h2>
            <button className="icon-btn" onClick={onClose} aria-label="Close"><X size={20} /></button>
          </div>
          <div className="dlg-body">{children}</div>
          {footer && <div className="dlg-foot">{footer}</div>}
        </>
      )}
    </dialog>
  );
}

export function ExpiryBadge({ exp }: { exp: string | null }) {
  const st = expiryState(exp);
  if (st === 'unknown') return <span className="badge">No expiry</span>;
  if (st === 'expired') return <span className="badge bad">Expired {monthLabel(exp)}</span>;
  if (st === 'soon') return <span className="badge warn">{daysLeft(exp!)} days left</span>;
  return <span className="badge ok">Good till {monthLabel(exp)}</span>;
}

export function Empty({ icon, title, children }: { icon: ReactNode; title: string; children?: ReactNode }) {
  return (
    <div className="empty">
      {icon}
      <p style={{ fontWeight: 700, color: 'var(--ink)' }}>{title}</p>
      {children && <div className="small" style={{ marginTop: 6 }}>{children}</div>}
    </div>
  );
}

export function PersonSelect({ people, value, onChange, allowFamily = true, id }: {
  people: Person[]; value: string | null; onChange: (v: string | null) => void; allowFamily?: boolean; id?: string;
}) {
  return (
    <select id={id} value={value ?? ''} onChange={e => onChange(e.target.value || null)}>
      {allowFamily && <option value="">Whole family</option>}
      {people.map(p => <option key={p.id} value={p.id}>{p.name}{p.relation && !p.is_self ? ` (${p.relation})` : ''}</option>)}
    </select>
  );
}

export const personName = (people: Person[], id: string | null) => (id ? people.find(p => p.id === id)?.name ?? 'Removed' : 'Whole family');

export const rupees = (n: number) => '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });

export function initials(name: string | null | undefined) {
  return (name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]?.toUpperCase()).join('');
}
