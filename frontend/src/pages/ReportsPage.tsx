import React, { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, CheckCircle2, ClipboardList, RefreshCw, XCircle } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import reportService from '@/services/reportService';

type LogStatus = 'success' | 'partial' | 'error';
interface OperationsLog {
  id: string;
  job: string;
  status: LogStatus;
  summary: Record<string, unknown> | null;
  duration_ms: number;
  triggered_by: string;
  started_at: string | null;
  finished_at: string | null;
}

const jobLabel = (job: string): string => ({
  recurring_invoices: 'Monthly billing invoices',
  update_overdue: 'Overdue invoice status update',
  invoice_reminders: 'Billing reminders',
  auto_suspend: 'Automatic suspension',
  db_backup: 'Database backup',
}[job] ?? job.replace(/_/g, ' '));

const statusStyle: Record<LogStatus, { label: string; className: string; Icon: typeof CheckCircle2 }> = {
  success: { label: 'Completed', className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300', Icon: CheckCircle2 },
  partial: { label: 'Warning', className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300', Icon: AlertTriangle },
  error: { label: 'Failed', className: 'bg-rose-500/10 text-rose-700 dark:text-rose-300', Icon: XCircle },
};

const ReportsPage: React.FC = () => {
  const [logs, setLogs] = useState<OperationsLog[]>([]);
  const [summary, setSummary] = useState({ total: 0, success: 0, warnings: 0, errors: 0 });
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const response = await reportService.getOperationsLog({ per_page: 50, status: status || undefined });
      setLogs(response.data ?? []);
      setSummary(response.summary ?? { total: 0, success: 0, warnings: 0, errors: 0 });
    } catch (requestError: any) {
      setError(requestError?.response?.data?.message || 'Unable to load operational logs. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [status]);

  useEffect(() => { void load(); }, [load]);

  return (
    <DashboardLayout>
      <div className="mx-auto max-w-7xl space-y-6 pb-10">
        <section className="rounded-3xl border border-primary/15 bg-gradient-to-br from-slate-950 via-slate-900 to-primary/90 px-6 py-7 text-white shadow-xl shadow-primary/10">
          <div className="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
            <div><div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-cyan-200"><ClipboardList className="h-4 w-4" /> Operations audit trail</div><h1 className="text-2xl font-semibold tracking-tight md:text-3xl">Logs & Reports</h1><p className="mt-2 text-sm text-slate-300">Billing runs, customer suspension actions, reminders, warnings, and system updates.</p></div>
            <button onClick={() => void load()} disabled={loading} className="inline-flex items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 py-2.5 text-sm font-semibold hover:bg-white/20 disabled:opacity-60"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh logs</button>
          </div>
        </section>

        <section className="grid grid-cols-2 gap-3 md:grid-cols-4">
          {[['All records', summary.total, 'bg-primary'], ['Completed', summary.success, 'bg-emerald-500'], ['Warnings', summary.warnings, 'bg-amber-500'], ['Errors', summary.errors, 'bg-rose-500']].map(([label, value, tone]) => <div key={String(label)} className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"><span className={`mb-3 block h-2 w-10 rounded-full ${tone}`} /><p className="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">{label}</p><p className="mt-2 text-3xl font-bold tabular-nums text-foreground">{value}</p></div>)}
        </section>

        <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
          <div className="flex flex-col gap-3 border-b border-border/70 p-5 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="font-semibold text-foreground">System activity</h2><p className="mt-1 text-sm text-muted-foreground">Each scheduled or manual automation run is recorded here.</p></div><select value={status} onChange={(event) => setStatus(event.target.value)} className="h-10 rounded-xl border border-input bg-background px-3 text-sm outline-none focus:border-primary"><option value="">All statuses</option><option value="success">Completed</option><option value="partial">Warnings</option><option value="error">Errors</option></select></div>
          {error ? <div className="m-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200">{error}</div> : <div className="overflow-x-auto"><table className="w-full min-w-[720px] text-left text-sm"><thead className="bg-muted/45 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground"><tr><th className="px-5 py-3">Activity</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Result</th><th className="px-5 py-3">When</th><th className="px-5 py-3">Source</th></tr></thead><tbody className="divide-y divide-border/70">{loading ? <tr><td colSpan={5} className="px-5 py-12 text-center text-muted-foreground">Loading operational logs…</td></tr> : logs.length === 0 ? <tr><td colSpan={5} className="px-5 py-12 text-center text-muted-foreground">No records yet. Billing and notification events will appear here.</td></tr> : logs.map((log) => { const presentation = statusStyle[log.status]; const Icon = presentation.Icon; const result = log.status === 'error' ? String(log.summary?.error ?? 'Action failed') : log.status === 'partial' ? 'Completed with warnings' : `${Number(log.summary?.generated ?? log.summary?.sent ?? log.summary?.suspended ?? log.summary?.flipped_to_overdue ?? 0)} action(s) completed`; return <tr key={log.id} className="hover:bg-muted/30"><td className="px-5 py-4 font-medium capitalize text-foreground">{jobLabel(log.job)}</td><td className="px-5 py-4"><span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${presentation.className}`}><Icon className="h-3.5 w-3.5" />{presentation.label}</span></td><td className="max-w-xs truncate px-5 py-4 text-muted-foreground">{result}</td><td className="px-5 py-4 text-muted-foreground">{log.finished_at ? new Date(log.finished_at).toLocaleString() : 'In progress'}</td><td className="px-5 py-4 capitalize text-muted-foreground">{log.triggered_by}</td></tr>; })}</tbody></table></div>}
        </section>
      </div>
    </DashboardLayout>
  );
};

export default ReportsPage;
