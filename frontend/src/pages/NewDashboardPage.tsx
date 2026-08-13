import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Activity,
  Banknote,
  CheckCircle2,
  CircleAlert,
  CircleDollarSign,
  CircleOff,
  Clock3,
  CreditCard,
  Gauge,
  Radio,
  RefreshCw,
  Search,
  ShieldCheck,
  Users,
  Wifi,
  type LucideIcon,
} from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import { logger } from '@/lib/logger';

interface ClientMonitor {
  customer_id: string;
  full_name: string;
  customer_status: string;
  ip_address: string;
  lease_status: string;
  last_seen_at: string | null;
  router_name: string | null;
  queue_name: string;
  queue_found: boolean;
  queue_snapshot_at: string | null;
  traffic: { download_bps: number | null; upload_bps: number | null; download_bytes: number | null; upload_bytes: number | null };
  service_plan: {
    name: string;
    download_speed: number;
    upload_speed: number;
  } | null;
}

interface DashboardMetrics {
  active_subscribers: number;
  expired_subscribers: number;
  suspended_subscribers: number;
  total_subscribers: number;
  today_revenue: number;
  monthly_revenue: number;
  pending_payments: number;
  overdue_invoices: number;
  paid_invoices: number;
  partial_invoices: number;
  unpaid_invoices: number;
  total_billed: number;
  total_paid: number;
  partial_paid: number;
  collectible: number;
  collection_rate: number;
  online_users: number;
  offline_users: number;
  router_status: { online: number; offline: number; error: number; total: number };
  automation_activity: Array<{ id: string; job: string; status: 'success' | 'partial' | 'error'; summary: Record<string, unknown> | null; finished_at: string | null }>;
  client_monitor: ClientMonitor[];
}

const peso = (value: number): string =>
  new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(value);

const titleCase = (value: string): string => value.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const statusTheme = (status: string): { label: string; className: string; Icon: LucideIcon } => {
  switch (status) {
    case 'active':
      return { label: 'Active', className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300', Icon: CheckCircle2 };
    case 'suspended':
      return { label: 'Suspended', className: 'bg-rose-500/10 text-rose-700 dark:text-rose-300', Icon: CircleOff };
    case 'expired':
      return { label: 'Disconnected', className: 'bg-slate-500/10 text-slate-700 dark:text-slate-300', Icon: CircleOff };
    default:
      return { label: titleCase(status || 'pending'), className: 'bg-amber-500/10 text-amber-700 dark:text-amber-300', Icon: Clock3 };
  }
};

const activityLabel = (job: string): string => ({
  recurring_invoices: 'Monthly billing run',
  update_overdue: 'Overdue invoice update',
  invoice_reminders: 'Payment reminders',
  auto_suspend: 'Automatic suspension',
  db_backup: 'Database backup',
}[job] ?? titleCase(job));

const formatRate = (bitsPerSecond: number | null): string => {
  if (bitsPerSecond === null) return '—';
  if (bitsPerSecond >= 1_000_000) return `${(bitsPerSecond / 1_000_000).toFixed(1)} Mbps`;
  if (bitsPerSecond >= 1_000) return `${(bitsPerSecond / 1_000).toFixed(1)} Kbps`;
  return `${bitsPerSecond} bps`;
};

const formatBytes = (bytes: number | null): string => {
  if (bytes === null) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let value = bytes;
  let index = 0;
  while (value >= 1024 && index < units.length - 1) { value /= 1024; index += 1; }
  return `${value.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
};

type TrafficSample = { download: number; upload: number };

const QueueTraffic = ({ customer, history }: { customer: ClientMonitor; history: TrafficSample[] }): React.JSX.Element => {
  const hasLiveRate = customer.traffic.download_bps !== null || customer.traffic.upload_bps !== null;

  if (!hasLiveRate) {
    return <span className="text-xs">↓ {formatBytes(customer.traffic.download_bytes)} · ↑ {formatBytes(customer.traffic.upload_bytes)}</span>;
  }

  const samples = history.length ? history : [{ download: customer.traffic.download_bps ?? 0, upload: customer.traffic.upload_bps ?? 0 }];
  const maximum = Math.max(1, ...samples.flatMap((sample) => [sample.download, sample.upload]));
  const path = (key: keyof TrafficSample): string => samples.map((sample, index) => {
    const x = samples.length === 1 ? 0 : (index / (samples.length - 1)) * 160;
    const y = 44 - ((sample[key] / maximum) * 40);
    return `${index === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`;
  }).join(' ');

  return <div className="min-w-[172px]">
    <div className="mb-1 flex items-center justify-between gap-2 text-[11px] font-medium tabular-nums"><span className="text-sky-600 dark:text-sky-400">↓ {formatRate(customer.traffic.download_bps)}</span><span className="text-red-600 dark:text-red-400">↑ {formatRate(customer.traffic.upload_bps)}</span></div>
    <svg viewBox="0 0 160 48" className="h-12 w-full overflow-visible" role="img" aria-label="Live download and upload traffic graph">
      <path d="M0 44 H160" stroke="currentColor" className="text-border" strokeWidth="1" />
      <path d={path('download')} fill="none" stroke="#0ea5e9" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="transition-all duration-700" />
      <path d={path('upload')} fill="none" stroke="#ef4444" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="transition-all duration-700" />
    </svg>
    <p className="mt-0.5 text-[10px] text-muted-foreground">Blue download · Red upload · 5s samples</p>
  </div>;
};

const MetricTile = ({ label, value, Icon, tone }: { label: string; value: string | number; Icon: LucideIcon; tone: string }) => (
  <div className="group relative overflow-hidden rounded-2xl border border-border/70 bg-card p-5 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-xl hover:shadow-primary/5">
    <div className={`absolute -right-5 -top-5 h-24 w-24 rounded-full opacity-20 blur-2xl ${tone}`} />
    <div className="relative flex items-start justify-between gap-3">
      <div>
        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">{label}</p>
        <p className="mt-2 text-2xl font-bold tracking-tight text-foreground tabular-nums">{value}</p>
      </div>
      <div className={`flex h-10 w-10 items-center justify-center rounded-xl text-white shadow-lg ${tone}`}>
        <Icon className="h-5 w-5" strokeWidth={2.25} />
      </div>
    </div>
  </div>
);

const NewDashboardPage: React.FC = () => {
  const [metrics, setMetrics] = useState<DashboardMetrics | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [query, setQuery] = useState('');
  const [clientMonitor, setClientMonitor] = useState<ClientMonitor[]>([]);
  const [trafficHistory, setTrafficHistory] = useState<Record<string, TrafficSample[]>>({});
  const [monitorUpdatedAt, setMonitorUpdatedAt] = useState<string | null>(null);
  const [monitorPolling, setMonitorPolling] = useState(false);
  const monitorRequestInFlight = useRef(false);

  const fetchMetrics = useCallback(async (manual = false): Promise<void> => {
    if (manual) setRefreshing(true);
    try {
      const response = await api.get<{ data: DashboardMetrics }>('/dashboard/metrics');
      setMetrics(response.data.data);
      setClientMonitor(response.data.data.client_monitor ?? []);
    } catch (error) {
      logger.error('Failed to fetch dashboard metrics', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  const fetchClientMonitor = useCallback(async (): Promise<void> => {
    if (monitorRequestInFlight.current) return;

    monitorRequestInFlight.current = true;
    setMonitorPolling(true);
    try {
      // The timestamp prevents an intermediary from returning a cached queue
      // snapshot. The backend polls the actual RouterOS Simple Queue counters.
      const response = await api.get<{ data: ClientMonitor[]; refreshed_at: string }>('/dashboard/client-monitor', {
        params: { _: Date.now() },
        headers: { 'Cache-Control': 'no-cache' },
      });
      setClientMonitor(response.data.data ?? []);
      setTrafficHistory((previous) => {
        const next = { ...previous };
        (response.data.data ?? []).forEach((customer) => {
          const sample = { download: customer.traffic.download_bps ?? 0, upload: customer.traffic.upload_bps ?? 0 };
          next[customer.customer_id] = [...(next[customer.customer_id] ?? []), sample].slice(-18);
        });
        return next;
      });
      setMonitorUpdatedAt(response.data.refreshed_at ?? new Date().toISOString());
    } catch (error) {
      // Preserve the last good counters when a router is temporarily offline.
      logger.error('Failed to refresh client queue monitor', error);
    } finally {
      monitorRequestInFlight.current = false;
      setMonitorPolling(false);
    }
  }, []);

  useEffect(() => {
    fetchMetrics();
    const interval = window.setInterval(() => fetchMetrics(), 30000);
    return () => window.clearInterval(interval);
  }, [fetchMetrics]);

  useEffect(() => {
    fetchClientMonitor();
    const interval = window.setInterval(fetchClientMonitor, 5000);
    return () => window.clearInterval(interval);
  }, [fetchClientMonitor]);

  const customers = useMemo(() => {
    const normalizedQuery = query.trim().toLowerCase();
    if (!normalizedQuery) return clientMonitor;
    return clientMonitor.filter((customer) =>
      [customer.full_name, customer.ip_address, customer.queue_name, customer.service_plan?.name]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(normalizedQuery)),
    );
  }, [clientMonitor, query]);

  const collectionRate = Math.min(100, Math.max(0, metrics?.collection_rate ?? 0));

  return (
    <DashboardLayout>
      <div className="mx-auto flex max-w-7xl flex-col gap-6 pb-10">
        <section className="relative overflow-hidden rounded-3xl border border-primary/15 bg-gradient-to-br from-slate-950 via-slate-900 to-primary/90 px-6 py-7 text-white shadow-2xl shadow-primary/15 md:px-8">
          <div className="absolute inset-0 opacity-30 [background-image:radial-gradient(circle_at_1px_1px,white_1px,transparent_0)] [background-size:22px_22px]" />
          <div className="absolute -right-16 -top-20 h-64 w-64 rounded-full bg-cyan-400/30 blur-3xl" />
          <div className="relative flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
            <div>
              <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-cyan-200">
                <span className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-400/20"><Activity className="h-3.5 w-3.5 text-emerald-300" /></span>
                Operations command center
              </div>
              <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">Client & billing overview</h1>
              <p className="mt-2 max-w-2xl text-sm text-slate-300">A real-time view of your subscribers, collections, and network readiness.</p>
            </div>
            <button
              type="button"
              onClick={() => fetchMetrics(true)}
              disabled={refreshing}
              className="inline-flex items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white backdrop-blur transition hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-70"
            >
              <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
              Refresh data
            </button>
          </div>
        </section>

        <section>
          <div className="mb-3 flex items-center gap-2">
            <div className="h-2 w-2 rounded-full bg-primary" />
            <h2 className="text-sm font-bold uppercase tracking-[0.14em] text-foreground">Client & billing overview</h2>
          </div>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <MetricTile label="All clients" value={metrics?.total_subscribers ?? 0} Icon={Users} tone="bg-primary" />
            <MetricTile label="Paid" value={metrics?.paid_invoices ?? 0} Icon={CheckCircle2} tone="bg-emerald-500" />
            <MetricTile label="Unpaid" value={metrics?.unpaid_invoices ?? 0} Icon={CircleAlert} tone="bg-rose-500" />
            <MetricTile label="Partial" value={metrics?.partial_invoices ?? 0} Icon={CreditCard} tone="bg-amber-500" />
            <MetricTile label="Active" value={metrics?.active_subscribers ?? 0} Icon={Wifi} tone="bg-cyan-500" />
            <MetricTile label="Suspended" value={metrics?.suspended_subscribers ?? 0} Icon={ShieldCheck} tone="bg-orange-500" />
            <MetricTile label="Disconnected" value={metrics?.expired_subscribers ?? 0} Icon={CircleOff} tone="bg-slate-600" />
            <MetricTile label="Collectible" value={peso(metrics?.collectible ?? 0)} Icon={Banknote} tone="bg-violet-600" />
          </div>
        </section>

        <section className="grid gap-5 lg:grid-cols-2">
          <div className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">Collection</p>
                <h2 className="mt-1 text-xl font-semibold text-foreground">Revenue pulse</h2>
              </div>
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><CircleDollarSign className="h-5 w-5" /></div>
            </div>
            <div className="mt-6 grid grid-cols-3 gap-3">
              <div><p className="text-xs text-muted-foreground">Today</p><p className="mt-1 font-semibold tabular-nums">{peso(metrics?.today_revenue ?? 0)}</p></div>
              <div><p className="text-xs text-muted-foreground">This month</p><p className="mt-1 font-semibold tabular-nums">{peso(metrics?.monthly_revenue ?? 0)}</p></div>
              <div><p className="text-xs text-muted-foreground">Overdue</p><p className="mt-1 font-semibold tabular-nums text-rose-600 dark:text-rose-400">{metrics?.overdue_invoices ?? 0}</p></div>
            </div>
            <div className="mt-7">
              <div className="mb-2 flex items-center justify-between text-sm"><span className="text-muted-foreground">Collection rate</span><span className="font-bold tabular-nums">{collectionRate}%</span></div>
              <div className="h-2.5 overflow-hidden rounded-full bg-secondary"><div className="h-full rounded-full bg-gradient-to-r from-cyan-500 to-emerald-500 transition-all duration-700" style={{ width: `${collectionRate}%` }} /></div>
            </div>
          </div>

          <div className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
            <div className="flex items-start justify-between gap-4">
              <div><p className="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">Billing summary</p><h2 className="mt-1 text-xl font-semibold text-foreground">Account position</h2></div>
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><Gauge className="h-5 w-5" /></div>
            </div>
            <div className="mt-5 divide-y divide-border/70">
              {[
                ['Total billed', peso(metrics?.total_billed ?? 0)],
                ['Paid', peso(metrics?.total_paid ?? 0)],
                ['Partial', peso(metrics?.partial_paid ?? 0)],
                ['Outstanding', peso(metrics?.collectible ?? 0)],
              ].map(([label, value]) => <div key={label} className="flex items-center justify-between py-3 text-sm"><span className="text-muted-foreground">{label}</span><span className="font-semibold tabular-nums text-foreground">{value}</span></div>)}
            </div>
          </div>
        </section>

        <section className="order-last rounded-2xl border border-border/70 bg-card p-5 shadow-sm">
          <div className="flex items-center justify-between gap-4">
            <div><p className="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">Operations log</p><h2 className="mt-1 text-xl font-semibold text-foreground">Billing updates & warnings</h2></div>
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><Activity className="h-5 w-5" /></div>
          </div>
          <div className="mt-5 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
            {(metrics?.automation_activity ?? []).length === 0 && !loading && <p className="text-sm text-muted-foreground">No billing automation logs yet. Scheduled runs and manual actions will appear here.</p>}
            {(metrics?.automation_activity ?? []).map((activity) => {
              const hasWarning = activity.status !== 'success' || Boolean(activity.summary?.errors) || Boolean(activity.summary?.error);
              const detail = activity.status === 'error' ? String(activity.summary?.error ?? 'The job needs attention') : activity.status === 'partial' ? 'Completed with warnings' : 'Completed successfully';
              return <div key={activity.id} className="rounded-xl border border-border/70 bg-muted/25 p-4"><div className="flex items-start gap-3"><div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${hasWarning ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'}`}>{hasWarning ? <CircleAlert className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}</div><div className="min-w-0"><p className="font-semibold text-foreground">{activityLabel(activity.job)}</p><p className="mt-1 text-sm text-muted-foreground">{detail}</p><p className="mt-2 text-xs text-muted-foreground">{activity.finished_at ? new Date(activity.finished_at).toLocaleString() : 'In progress'}</p></div></div></div>;
            })}
          </div>
        </section>

        <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
          <div className="flex flex-col gap-4 border-b border-border/70 p-5 md:flex-row md:items-center md:justify-between">
            <div>
              <div className="flex items-center gap-2"><Radio className="h-4 w-4 text-primary" /><h2 className="font-semibold text-foreground">Live queue & lease monitor</h2></div>
              <p className="mt-1 text-sm text-muted-foreground">Live Simple Queue traffic · polls MikroTik every 5 seconds without reloading this page{monitorUpdatedAt ? ` · last checked ${new Date(monitorUpdatedAt).toLocaleTimeString()}` : ''}{monitorPolling ? ' · checking…' : ''}.</p>
            </div>
            <label className="relative block md:w-72"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search recent clients" className="h-10 w-full rounded-xl border border-input bg-background pl-9 pr-3 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15" /></label>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-left text-sm">
              <thead className="bg-muted/45 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground"><tr><th className="px-5 py-3">Client</th><th className="px-5 py-3">Lease</th><th className="px-5 py-3">Queue</th><th className="px-5 py-3">Plan</th><th className="px-5 py-3">Traffic</th><th className="px-5 py-3">Status</th></tr></thead>
              <tbody className="divide-y divide-border/70">
                {loading ? <tr><td colSpan={6} className="px-5 py-12 text-center text-muted-foreground">Loading client monitor…</td></tr> : customers.length === 0 ? <tr><td colSpan={6} className="px-5 py-12 text-center text-muted-foreground">No matched DHCP client leases yet. Sync a router to populate this monitor.</td></tr> : customers.map((customer) => {
                  const theme = statusTheme(customer.customer_status);
                  const StatusIcon = theme.Icon;
                  return <tr key={customer.customer_id} className="transition-colors hover:bg-muted/35"><td className="px-5 py-4 font-medium text-foreground">{customer.full_name}</td><td className="px-5 py-4 text-muted-foreground"><p>{customer.ip_address}</p><p className="mt-1 text-xs capitalize">{customer.lease_status}</p></td><td className="px-5 py-4 text-muted-foreground"><p className="font-mono text-xs">{customer.queue_name}</p><p className={`mt-1 text-xs ${customer.queue_found ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'}`}>{customer.queue_found ? 'Queue found' : 'Awaiting queue sync'}</p></td><td className="px-5 py-4 text-muted-foreground">{customer.service_plan ? `${customer.service_plan.name} · ${customer.service_plan.download_speed}/${customer.service_plan.upload_speed} Mbps` : 'No plan'}</td><td className="px-5 py-4 text-muted-foreground"><QueueTraffic customer={customer} history={trafficHistory[customer.customer_id] ?? []} /></td><td className="px-5 py-4"><span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${theme.className}`}><StatusIcon className="h-3.5 w-3.5" />{theme.label}</span></td></tr>;
                })}
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </DashboardLayout>
  );
};

export default NewDashboardPage;
