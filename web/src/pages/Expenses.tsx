import { useMemo, useState } from 'react';
import { Download, Plus, Trash2, Wallet } from 'lucide-react';
import { canEdit, useData, useStore } from '../data/store';
import { financialYear, isoDay } from '../lib/dates';
import { t } from '../lib/i18n';
import type { Expense, ExpenseCategory } from '../lib/types';
import { Dialog, Empty, PersonSelect, personName, rupees } from '../components/ui';

const CATS: { k: ExpenseCategory; label: string }[] = [
  { k: 'medicine', label: 'Medicines' }, { k: 'consult', label: 'Doctor fees' }, { k: 'test', label: 'Tests' },
  { k: 'checkup', label: 'Health check-ups' }, { k: 'other', label: 'Other' },
];

export default function Expenses() {
  const d = useData();
  const { act } = useStore();
  const years = useMemo(() => Array.from(new Set([financialYear(isoDay()), ...d.expenses.map(e => financialYear(e.spent_on))])).sort().reverse(), [d.expenses]);
  const [fy, setFy] = useState(years[0]);
  const [adding, setAdding] = useState(false);
  const rows = d.expenses.filter(e => financialYear(e.spent_on) === fy).sort((a, b) => b.spent_on.localeCompare(a.spent_on));
  const total = rows.reduce((s, e) => s + e.amount, 0);
  const byCat = CATS.map(c => ({ ...c, sum: rows.filter(e => e.category === c.k).reduce((s, e) => s + e.amount, 0) }));
  const checkups = byCat.find(c => c.k === 'checkup')!.sum;
  const start = Number(fy.slice(0, 4));
  const months = Array.from({ length: 12 }, (_, i) => {
    const m = (3 + i) % 12, y = start + (3 + i >= 12 ? 1 : 0);
    const key = `${y}-${String(m + 1).padStart(2, '0')}`;
    return { label: new Date(y, m, 1).toLocaleDateString(undefined, { month: 'short' }), sum: rows.filter(e => e.spent_on.startsWith(key)).reduce((s, e) => s + e.amount, 0) };
  });
  const max = Math.max(1, ...months.map(m => m.sum));
  const editable = canEdit(d);

  function exportCsv() {
    const esc = (s: string) => `"${s.replace(/"/g, '""')}"`;
    // a leading = + - @ would be run as a formula by spreadsheet apps: prefix with '
    const safe = (s: string) => esc(/^[=+\-@]/.test(s) ? `'${s}` : s);
    const lines = [['Date', 'Person', 'Category', 'Amount (INR)', 'Note'].join(','),
      ...rows.map(e => [e.spent_on, safe(personName(d.people, e.person_id)), e.category, e.amount.toFixed(2), safe(e.note ?? '')].join(','))];
    const url = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
    const a = Object.assign(document.createElement('a'), { href: url, download: `medical-expenses-FY${fy}.csv` });
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <div className="page">
      <div className="page-head">
        <div><h1>{t('expenses')}</h1><p>Medical spending by financial year, ready for tax filing.</p></div>
        <div className="row">
          <select value={fy} onChange={e => setFy(e.target.value)} aria-label="Financial year" style={{ width: 'auto' }}>{years.map(y => <option key={y} value={y}>FY {y}</option>)}</select>
          <button className="btn" onClick={exportCsv} disabled={!rows.length}><Download size={18} aria-hidden /> CSV</button>
          {editable && <button className="btn primary" onClick={() => setAdding(true)}><Plus size={18} aria-hidden /> {t('add')}</button>}
        </div>
      </div>

      <section className="stats">
        <div className="card stat"><b className="mono">{rupees(total)}</b><span>Total in FY {fy}</span></div>
        {byCat.slice(0, 3).map(c => <div key={c.k} className="card stat"><b className="mono">{rupees(c.sum)}</b><span>{c.label}</span></div>)}
      </section>

      {checkups > 0 && <p className="banner info">Preventive health check-ups: {rupees(checkups)} this year. Section 80D allows up to ₹5,000 of this within the health insurance limit; ask your tax adviser.</p>}

      <div className="grid dash">
        <section className="card" aria-labelledby="h-month"><h2 id="h-month">By month</h2>
          <div className="bars" role="img" aria-label={`Monthly spending, highest ${rupees(max)}`}>
            {months.map(m => <div key={m.label}><i style={{ height: `${(m.sum / max) * 100}%` }} title={rupees(m.sum)} /><span>{m.label}</span></div>)}
          </div>
        </section>
        <section className="card flush" aria-labelledby="h-list"><h2 id="h-list">Entries</h2>
          {rows.length === 0 ? <Empty icon={<Wallet size={36} />} title="No expenses this year." /> : (
            <ul className="list">
              {rows.map(e => (
                <li key={e.id}>
                  <span className="grow"><b>{CATS.find(c => c.k === e.category)?.label}</b> <span className="muted small">· {personName(d.people, e.person_id)}</span><br />
                    <span className="small muted">{new Date(e.spent_on).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}{e.note ? ` · ${e.note}` : ''}</span></span>
                  <b className="mono">{rupees(e.amount)}</b>
                  {editable && <button className="icon-btn" aria-label="Delete expense" onClick={() => act(r => r.deleteExpense(e.id))}><Trash2 size={16} /></button>}
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      {adding && <AddExpense onClose={() => setAdding(false)} onSave={async e => { await act(r => r.addExpense(e), 'Expense added'); setAdding(false); }} />}
    </div>
  );
}

function AddExpense({ onClose, onSave }: { onClose: () => void; onSave: (e: Omit<Expense, 'id' | 'household_id'>) => void }) {
  const d = useData();
  const [e, setE] = useState<Omit<Expense, 'id' | 'household_id'>>({ person_id: null, category: 'medicine', amount: 0, spent_on: isoDay(), note: null });
  const [amount, setAmount] = useState('');
  const [err, setErr] = useState<string | null>(null);
  return (
    <Dialog open title="Add an expense" onClose={onClose}
      footer={<><button className="btn" onClick={onClose}>{t('cancel')}</button>
        <button className="btn primary" onClick={() => {
          const n = Number(amount.replace(/[,₹\s]/g, ''));
          if (!(n > 0 && n < 10000000)) return setErr('Type the amount in rupees.');
          onSave({ ...e, amount: Math.round(n * 100) / 100 });
        }}>{t('save')}</button></>}>
      <div className="form-grid">
        <label className="field"><span>Amount (₹)</span><input inputMode="decimal" value={amount} onChange={x => setAmount(x.target.value)} autoFocus /></label>
        <label className="field"><span>Date</span><input type="date" value={e.spent_on} max={isoDay()} onChange={x => setE({ ...e, spent_on: x.target.value })} /></label>
        <label className="field"><span>Category</span><select value={e.category} onChange={x => setE({ ...e, category: x.target.value as ExpenseCategory })}>{CATS.map(c => <option key={c.k} value={c.k}>{c.label}</option>)}</select></label>
        <label className="field"><span>{t('forWhom')}</span><PersonSelect people={d.people} value={e.person_id} onChange={v => setE({ ...e, person_id: v })} /></label>
        <label className="field full"><span>{t('notes')}</span><input value={e.note ?? ''} onChange={x => setE({ ...e, note: x.target.value || null })} maxLength={160} placeholder="Pharmacy or hospital name" /></label>
      </div>
      {err && <p role="alert" className="banner" style={{ marginTop: 14, background: 'var(--bad-soft)', color: 'var(--bad)' }}>{err}</p>}
    </Dialog>
  );
}
