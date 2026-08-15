import React, { useState } from 'react';
import { AlertTriangle, Eye, Loader2, ShieldCheck, Trash2 } from 'lucide-react';
import {
  HISTORICAL_CLEANUP_CONFIRMATION,
  historicalCleanupService,
  type CleanupPreview,
  type HistoricalCleanupModule,
} from '@/services/historicalCleanupService';

const MODULES: Array<{ value: HistoricalCleanupModule; label: string; detail: string }> = [
  { value: 'past_transactions', label: 'Past transactions', detail: 'Select with Invoices and Liquidations to remove linked test payment bundles.' },
  { value: 'daily_operations', label: 'Daily-operation records', detail: 'Historical operational entries only.' },
  { value: 'invoices', label: 'Historical invoices', detail: 'Paid/cancelled invoices whose selected-range payments are also removed.' },
  { value: 'tickets', label: 'Closed repair tickets', detail: 'Closed repair tickets and their child comments/history.' },
  { value: 'liquidations', label: 'Historical remittances', detail: 'Select with Past transactions to remove its linked test payments safely.' },
  { value: 'installation_applications', label: 'Finished or rejected installation applications', detail: 'The application ticket only; never the customer.' },
];

export function HistoricalCleanupPanel(): React.JSX.Element {
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [modules, setModules] = useState<HistoricalCleanupModule[]>([]);
  const [preview, setPreview] = useState<CleanupPreview | null>(null);
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const toggle = (module: HistoricalCleanupModule): void => {
    setPreview(null); setConfirmation('');
    setModules(current => current.includes(module) ? current.filter(value => value !== module) : [...current, module]);
  };
  const createPreview = async (): Promise<void> => {
    setBusy(true); setError(''); setMessage(''); setPreview(null);
    try { setPreview(await historicalCleanupService.preview({ from_date: fromDate, to_date: toDate, modules })); }
    catch (err: any) { setError(err?.response?.data?.message || 'Could not create a cleanup preview.'); }
    finally { setBusy(false); }
  };
  const execute = async (): Promise<void> => {
    if (!preview) return;
    setBusy(true); setError(''); setMessage('');
    try {
      await historicalCleanupService.execute(preview.preview_token, confirmation);
      setMessage('Cleanup completed. The audit record confirms that zero customer records were deleted.');
      setPreview(null); setConfirmation(''); setModules([]);
    } catch (err: any) { setError(err?.response?.data?.message || 'Cleanup did not run.'); }
    finally { setBusy(false); }
  };

  return <section className="rounded-xl border border-rose-200 bg-rose-50/40 p-5 dark:border-rose-900/50 dark:bg-rose-950/20">
    <div className="flex gap-3"><div className="rounded-lg bg-rose-100 p-2 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300"><AlertTriangle className="h-5 w-5" /></div><div><h2 className="font-semibold text-foreground">Historical data cleanup</h2><p className="mt-1 text-sm text-muted-foreground">Super Administrator only. Preview records first, then enter the exact confirmation phrase to execute.</p></div></div>
    <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200"><strong>Protected:</strong> customers, users, router/network settings, DHCP leases, service plans, payments, credits, active invoices, and accounting-linked records are never deleted. Back up production data before any cleanup.</div>
    <div className="mt-4 grid gap-3 sm:grid-cols-2"><label className="text-sm font-medium text-foreground">From date<input required type="date" value={fromDate} onChange={e => { setFromDate(e.target.value); setPreview(null); }} className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-foreground" /></label><label className="text-sm font-medium text-foreground">To date<input required type="date" value={toDate} onChange={e => { setToDate(e.target.value); setPreview(null); }} className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-foreground" /></label></div>
    <div className="mt-4 space-y-2">{MODULES.map(module => <label key={module.value} className="flex cursor-pointer gap-3 rounded-lg border border-border bg-background p-3"><input type="checkbox" checked={modules.includes(module.value)} onChange={() => toggle(module.value)} className="mt-1" /><span><span className="block text-sm font-medium text-foreground">{module.label}</span><span className="text-xs text-muted-foreground">{module.detail}</span></span></label>)}</div>
    <button type="button" onClick={() => void createPreview()} disabled={busy || !fromDate || !toDate || modules.length === 0} className="mt-4 inline-flex items-center gap-2 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50"><Eye className="h-4 w-4" />{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : null} Preview eligible records</button>
    {error && <p className="mt-3 text-sm text-rose-700">{error}</p>}{message && <p className="mt-3 text-sm text-emerald-700">{message}</p>}
    {preview && <div className="mt-5 rounded-lg border border-rose-200 bg-background p-4"><div className="flex items-center gap-2 text-sm font-medium text-foreground"><ShieldCheck className="h-4 w-4 text-emerald-600" />Preview only — nothing has been changed</div><p className="mt-2 text-sm text-muted-foreground">{preview.warning}</p><div className="mt-3 space-y-2">{Object.entries(preview.modules).map(([key, item]) => <div key={key} className="flex justify-between rounded bg-muted/40 px-3 py-2 text-sm"><span>{item.label}</span><span><b>{item.eligible}</b> eligible{item.blocked > 0 ? ` · ${item.blocked} protected/blocked` : ''}</span></div>)}</div><p className="mt-3 text-xs text-muted-foreground">Preview expires in {Math.ceil(preview.expires_in_seconds / 60)} minutes. Customer records deleted: 0.</p><label className="mt-4 block text-sm font-medium text-foreground">Type <code className="rounded bg-muted px-1">{HISTORICAL_CLEANUP_CONFIRMATION}</code><input value={confirmation} onChange={e => setConfirmation(e.target.value)} className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-foreground" /></label><button type="button" onClick={() => void execute()} disabled={busy || confirmation !== HISTORICAL_CLEANUP_CONFIRMATION} className="mt-3 inline-flex items-center gap-2 rounded-lg bg-rose-700 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50"><Trash2 className="h-4 w-4" />{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : null} Delete only previewed records</button></div>}
  </section>;
}
