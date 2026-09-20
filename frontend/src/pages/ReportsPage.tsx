import React, { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, CheckCircle2, ClipboardList, RefreshCw, Search, ShieldCheck, Users, XCircle } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import reportService from '@/services/reportService';

type LogStatus = 'success' | 'partial' | 'error';
interface OperationsLog { id: string; job: string; status: LogStatus; summary: Record<string, unknown> | null; duration_ms: number; triggered_by: string; finished_at: string | null; }
interface ActivityLog { id: string; actor_name: string | null; category: string; action: string; method: string; path: string; subject_type: string | null; subject_id: string | null; response_status: number; changes: Record<string, unknown> | null; ip_address: string | null; duration_ms: number; created_at: string; }

const jobLabel = (job: string): string => ({ recurring_invoices: 'Monthly billing invoices', update_overdue: 'Overdue invoice status update', invoice_reminders: 'Billing reminders', auto_suspend: 'Automatic suspension', db_backup: 'Database backup' }[job] ?? job.replace(/_/g, ' '));
const statusStyle: Record<LogStatus, { label: string; className: string; Icon: typeof CheckCircle2 }> = {
  success: { label: 'Completed', className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300', Icon: CheckCircle2 },
  partial: { label: 'Warning', className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300', Icon: AlertTriangle },
  error: { label: 'Failed', className: 'bg-rose-500/10 text-rose-700 dark:text-rose-300', Icon: XCircle },
};

const ReportsPage: React.FC = () => {
  const [activities, setActivities] = useState<ActivityLog[]>([]);
  const [activitySummary, setActivitySummary] = useState({ total: 0, success: 0, failed: 0, actors: 0 });
  const [logs, setLogs] = useState<OperationsLog[]>([]);
  const [summary, setSummary] = useState({ total: 0, success: 0, warnings: 0, errors: 0 });
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [outcome, setOutcome] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const [activityResponse, automationResponse] = await Promise.all([
        reportService.getActivityLog({ per_page: 100, search: search || undefined, category: category || undefined, outcome: outcome || undefined }),
        reportService.getOperationsLog({ per_page: 50, status: status || undefined }),
      ]);
      setActivities(activityResponse.data ?? []);
      setActivitySummary(activityResponse.summary ?? { total: 0, success: 0, failed: 0, actors: 0 });
      setLogs(automationResponse.data ?? []);
      setSummary(automationResponse.summary ?? { total: 0, success: 0, warnings: 0, errors: 0 });
    } catch (requestError: any) {
      setError(requestError?.response?.data?.message || 'Unable to load logs and reports. Please try again.');
    } finally { setLoading(false); }
  }, [search, category, outcome, status]);

  useEffect(() => { const timer = window.setTimeout(() => void load(), 250); return () => window.clearTimeout(timer); }, [load]);

  return <DashboardLayout><div className="mx-auto max-w-7xl space-y-6 pb-10">
    <section className="rounded-3xl border border-primary/15 bg-gradient-to-br from-slate-950 via-slate-900 to-primary/90 px-6 py-7 text-white shadow-xl shadow-primary/10">
      <div className="flex flex-col gap-5 md:flex-row md:items-center md:justify-between"><div><div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-cyan-200"><ClipboardList className="h-4 w-4" /> Complete audit trail</div><h1 className="text-2xl font-semibold md:text-3xl">Logs & Reports</h1><p className="mt-2 text-sm text-slate-300">Staff actions, transactions, record changes, failed attempts, and scheduled system jobs in one place.</p></div><button onClick={() => void load()} disabled={loading} className="inline-flex items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 py-2.5 text-sm font-semibold hover:bg-white/20 disabled:opacity-60"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh logs</button></div>
    </section>

    <section className="grid grid-cols-2 gap-3 md:grid-cols-4">
      {[[ShieldCheck, 'Recorded actions', activitySummary.total, 'bg-primary'], [CheckCircle2, 'Successful', activitySummary.success, 'bg-emerald-500'], [XCircle, 'Failed attempts', activitySummary.failed, 'bg-rose-500'], [Users, 'Staff actors', activitySummary.actors, 'bg-cyan-500']].map(([Icon, label, value, tone]) => { const CardIcon = Icon as typeof ShieldCheck; return <div key={String(label)} className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm"><span className={`mb-3 flex h-8 w-8 items-center justify-center rounded-lg text-white ${tone}`}><CardIcon className="h-4 w-4" /></span><p className="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">{String(label)}</p><p className="mt-2 text-3xl font-bold tabular-nums text-foreground">{String(value)}</p></div>; })}
    </section>

    <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
      <div className="border-b border-border/70 p-5"><h2 className="font-semibold text-foreground">Staff actions and transactions</h2><p className="mt-1 text-sm text-muted-foreground">Append-only history of authenticated create, update, delete, billing, payment, remittance, ticket, and administrative actions.</p><div className="mt-4 grid gap-3 md:grid-cols-[1fr_220px_180px]"><label className="relative"><Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search staff, action, route, or record ID" className="h-10 w-full rounded-xl border border-input bg-background pl-9 pr-3 text-sm outline-none focus:border-primary" /></label><select value={category} onChange={(e) => setCategory(e.target.value)} className="h-10 rounded-xl border border-input bg-background px-3 text-sm"><option value="">All areas</option><option value="customers">Customers</option><option value="invoices">Invoices</option><option value="payments">Payments</option><option value="remittances">Remittances</option><option value="tickets">Tickets</option><option value="users">Users</option><option value="financial-entries">Daily operations</option><option value="sms-advisories">SMS advisories</option></select><select value={outcome} onChange={(e) => setOutcome(e.target.value)} className="h-10 rounded-xl border border-input bg-background px-3 text-sm"><option value="">All results</option><option value="success">Successful</option><option value="failed">Failed</option></select></div></div>
      {error ? <div className="m-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200">{error}</div> : <div className="overflow-x-auto"><table className="w-full min-w-[980px] text-left text-sm"><thead className="bg-muted/45 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground"><tr><th className="px-5 py-3">When / staff</th><th className="px-5 py-3">Area / action</th><th className="px-5 py-3">Affected record</th><th className="px-5 py-3">Result</th><th className="px-5 py-3">Details</th></tr></thead><tbody className="divide-y divide-border/70">{loading ? <tr><td colSpan={5} className="px-5 py-12 text-center text-muted-foreground">Loading activity logs...</td></tr> : activities.length === 0 ? <tr><td colSpan={5} className="px-5 py-12 text-center text-muted-foreground">No matching actions recorded yet. New changes will appear after this audit feature is deployed.</td></tr> : activities.map((log) => <tr key={log.id} className="align-top hover:bg-muted/30"><td className="px-5 py-4"><p className="font-medium text-foreground">{log.actor_name || 'Authenticated user'}</p><p className="mt-1 text-xs text-muted-foreground">{new Date(log.created_at).toLocaleString()}</p></td><td className="px-5 py-4"><span className="rounded-full bg-primary/10 px-2 py-1 text-xs font-semibold capitalize text-primary">{log.category.replace(/-/g, ' ')}</span><p className="mt-2 max-w-sm text-foreground">{log.action}</p></td><td className="px-5 py-4 text-muted-foreground"><p>{log.subject_type || 'Request'}</p><p className="mt-1 max-w-[220px] truncate font-mono text-xs">{log.subject_id || log.path}</p></td><td className="px-5 py-4"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${log.response_status < 400 ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-rose-500/10 text-rose-700 dark:text-rose-300'}`}>{log.response_status < 400 ? 'Successful' : 'Failed'} · {log.response_status}</span></td><td className="px-5 py-4"><details className="max-w-sm"><summary className="cursor-pointer font-medium text-primary">View details</summary><div className="mt-2 rounded-xl bg-muted/60 p-3 text-xs text-muted-foreground"><p><b>{log.method}</b> {log.path}</p><p className="mt-1">IP: {log.ip_address || 'Not recorded'} · {log.duration_ms} ms</p><pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-all font-mono">{JSON.stringify(log.changes ?? {}, null, 2)}</pre></div></details></td></tr>)}</tbody></table></div>}
    </section>

    <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
      <div className="flex flex-col gap-3 border-b border-border/70 p-5 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="font-semibold text-foreground">Scheduled automation</h2><p className="mt-1 text-sm text-muted-foreground">Billing, reminders, suspension, and backup job results. {summary.total} record(s), {summary.errors} error(s).</p></div><select value={status} onChange={(e) => setStatus(e.target.value)} className="h-10 rounded-xl border border-input bg-background px-3 text-sm"><option value="">All statuses</option><option value="success">Completed</option><option value="partial">Warnings</option><option value="error">Errors</option></select></div>
      <div className="overflow-x-auto"><table className="w-full min-w-[720px] text-left text-sm"><thead className="bg-muted/45 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground"><tr><th className="px-5 py-3">Activity</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Result</th><th className="px-5 py-3">When</th><th className="px-5 py-3">Source</th></tr></thead><tbody className="divide-y divide-border/70">{logs.length === 0 ? <tr><td colSpan={5} className="px-5 py-10 text-center text-muted-foreground">No automation records found.</td></tr> : logs.map((log) => { const presentation = statusStyle[log.status]; const Icon = presentation.Icon; return <tr key={log.id}><td className="px-5 py-4 font-medium capitalize">{jobLabel(log.job)}</td><td className="px-5 py-4"><span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${presentation.className}`}><Icon className="h-3.5 w-3.5" />{presentation.label}</span></td><td className="max-w-xs truncate px-5 py-4 text-muted-foreground">{log.status === 'error' ? String(log.summary?.error ?? 'Action failed') : log.status === 'partial' ? 'Completed with warnings' : 'Completed successfully'}</td><td className="px-5 py-4 text-muted-foreground">{log.finished_at ? new Date(log.finished_at).toLocaleString() : 'In progress'}</td><td className="px-5 py-4 capitalize text-muted-foreground">{log.triggered_by}</td></tr>; })}</tbody></table></div>
    </section>
  </div></DashboardLayout>;
};

export default ReportsPage;
