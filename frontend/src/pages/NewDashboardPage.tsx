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
  MapPin,
  Navigation,
  Radio,
  RefreshCw,
  Search,
  ShieldCheck,
  Users,
  Wifi,
  type LucideIcon,
} from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { useAuth } from '@/hooks/useAuth';
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

interface ClientLocation {
  id: string;
  account_number: string;
  full_name: string;
  address: string | null;
  status: string;
  latitude: number;
  longitude: number;
}

interface CollectorClient {
  id: string;
  account_number: string;
  full_name: string;
  address: string | null;
  contact_number: string | null;
  status: string;
  gps_coordinates: { latitude: number; longitude: number } | null;
  service_plan: { id: string; name: string; price: number; download_speed: number; upload_speed: number } | null;
}

interface PlanOption { id: string; name: string; price: number; download_speed: number; upload_speed: number }

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

  return <div className="min-w-[145px]">
    <div className="mb-1 flex items-center justify-between gap-2 text-[11px] font-medium tabular-nums"><span className="text-sky-600 dark:text-sky-400">↓ {formatRate(customer.traffic.download_bps)}</span><span className="text-red-600 dark:text-red-400">↑ {formatRate(customer.traffic.upload_bps)}</span></div>
    <svg viewBox="0 0 160 48" className="h-8 w-full overflow-visible" role="img" aria-label="Live download and upload traffic graph">
      <path d="M0 44 H160" stroke="currentColor" className="text-border" strokeWidth="1" />
      <path d={path('download')} fill="none" stroke="#0ea5e9" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="transition-all duration-700" />
      <path d={path('upload')} fill="none" stroke="#ef4444" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="transition-all duration-700" />
    </svg>
    <p className="mt-0.5 text-[10px] text-muted-foreground">Blue download · Red upload · 5s samples</p>
  </div>;
};

const MetricTile = ({ label, value, Icon, tone }: { label: string; value: string | number; Icon: LucideIcon; tone: string }) => (
  <div className="group relative overflow-hidden rounded-md border border-border/70 bg-card p-2 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-lg hover:shadow-primary/5">
    <div className={`absolute -right-5 -top-5 h-16 w-16 rounded-full opacity-20 blur-2xl ${tone}`} />
    <div className="relative flex items-start justify-between gap-3">
      <div>
        <p className="text-[8px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">{label}</p>
        <p className="mt-0.5 text-base font-bold tracking-tight text-foreground tabular-nums">{value}</p>
      </div>
      <div className={`flex h-6 w-6 items-center justify-center rounded-md text-white shadow-md ${tone}`}>
        <Icon className="h-3 w-3" strokeWidth={2.25} />
      </div>
    </div>
  </div>
);

const NewDashboardPage: React.FC = () => {
  const { user } = useAuth();
  const collector = user?.role === 'collector' || user?.roles?.some((role) => typeof role === 'string' ? role === 'collector' : role.name === 'collector');
  const [metrics, setMetrics] = useState<DashboardMetrics | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [query, setQuery] = useState('');
  const [clientMonitor, setClientMonitor] = useState<ClientMonitor[]>([]);
  const [trafficHistory, setTrafficHistory] = useState<Record<string, TrafficSample[]>>({});
  const [monitorUpdatedAt, setMonitorUpdatedAt] = useState<string | null>(null);
  const [monitorPolling, setMonitorPolling] = useState(false);
  const [locations, setLocations] = useState<ClientLocation[]>([]);
  const [locationQuery, setLocationQuery] = useState('');
  const [selectedLocation, setSelectedLocation] = useState<ClientLocation | null>(null);
  const [collectorSearch, setCollectorSearch] = useState('');
  const [collectorClients, setCollectorClients] = useState<CollectorClient[]>([]);
  const [planOptions, setPlanOptions] = useState<PlanOption[]>([]);
  const [selectedPlans, setSelectedPlans] = useState<Record<string, string>>({});
  const [collectorActionBusy, setCollectorActionBusy] = useState<string | null>(null);
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

  const fetchLocations = useCallback(async (): Promise<void> => {
    try {
      const response = await api.get<{ data: ClientLocation[] }>('/collector/locations');
      const next = response.data.data ?? [];
      setLocations(next);
      setSelectedLocation((current) => current ? next.find((location) => location.id === current.id) ?? next[0] ?? null : next[0] ?? null);
    } catch (error) {
      logger.error('Failed to fetch collector client locations', error);
    }
  }, []);

  const fetchCollectorClients = useCallback(async (term = ''): Promise<void> => {
    try {
      const response = await api.get<{ data: CollectorClient[]; service_plans: PlanOption[] }>('/collector/clients', { params: { q: term } });
      setCollectorClients(response.data.data ?? []);
      setPlanOptions(response.data.service_plans ?? []);
    } catch (error) {
      logger.error('Failed to search collector clients', error);
    }
  }, []);

  useEffect(() => {
    fetchMetrics();
    const interval = window.setInterval(() => fetchMetrics(), 30000);
    return () => window.clearInterval(interval);
  }, [fetchMetrics]);

  useEffect(() => {
    if (collector) {
      fetchLocations();
      return;
    }
    fetchClientMonitor();
    const interval = window.setInterval(fetchClientMonitor, 5000);
    return () => window.clearInterval(interval);
  }, [collector, fetchClientMonitor, fetchLocations]);

  useEffect(() => { if (collector) void fetchCollectorClients(); }, [collector, fetchCollectorClients]);

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
  const locationMatches = useMemo(() => {
    const term = locationQuery.trim().toLowerCase();
    return term ? locations.filter((location) => [location.full_name, location.account_number, location.address].filter(Boolean).some((value) => String(value).toLowerCase().includes(term))) : locations;
  }, [locations, locationQuery]);
  const openNavigation = (location: ClientLocation) => window.open(`https://www.google.com/maps/dir/?api=1&destination=${location.latitude},${location.longitude}`, '_blank', 'noopener,noreferrer');
  const updateCollectorLocation = async (client: CollectorClient) => {
    const raw = window.prompt('Enter latitude and longitude, separated by a comma.', client.gps_coordinates ? `${client.gps_coordinates.latitude}, ${client.gps_coordinates.longitude}` : '');
    if (!raw) return;
    const [latitude, longitude] = raw.split(',').map((value) => Number(value.trim()));
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return window.alert('Enter valid coordinates in this format: 12.345678, 125.678901');
    setCollectorActionBusy(client.id);
    try { await api.put(`/collector/clients/${client.id}/location`, { latitude, longitude }); window.alert('Coordinates updated.'); await Promise.all([fetchCollectorClients(collectorSearch), fetchLocations()]); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not update client coordinates.'); }
    finally { setCollectorActionBusy(null); }
  };
  const requestPlanChange = async (client: CollectorClient) => {
    const servicePlanId = selectedPlans[client.id];
    if (!servicePlanId) return window.alert('Choose a requested service plan first.');
    setCollectorActionBusy(client.id);
    try { await api.post(`/collector/clients/${client.id}/plan-change-request`, { service_plan_id: servicePlanId }); window.alert('Plan change request sent for administrator approval.'); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not submit the plan change request.'); }
    finally { setCollectorActionBusy(null); }
  };
  const createEarlyInvoice = async (client: CollectorClient) => {
    if (!window.confirm(`Create an early payment invoice for ${client.full_name}?`)) return;
    setCollectorActionBusy(client.id);
    try { const response = await api.post(`/collector/clients/${client.id}/early-invoice`); window.alert(`${response.data.message} ${response.data.invoice?.invoice_number ?? ''}`); await fetchCollectorClients(collectorSearch); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not create the early invoice.'); }
    finally { setCollectorActionBusy(null); }
  };

  return (
    <DashboardLayout headerTitle="Client & billing overview" headerSubtitle="A real-time view of your subscribers, collections, and network readiness.">
      <div className="mx-auto flex max-w-7xl flex-col gap-6 pb-10">
        <section>
          <div className="mb-2 flex items-center justify-between gap-2">
            <div className="flex items-center gap-2">
            <div className="h-2 w-2 rounded-full bg-primary" />
            <h2 className="text-sm font-bold uppercase tracking-[0.14em] text-foreground">Client & billing overview</h2>
            </div>
            <button type="button" onClick={() => fetchMetrics(true)} disabled={refreshing} className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground disabled:opacity-70"><RefreshCw className={`h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} />Refresh data</button>
          </div>
          <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
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

        {!collector && <section className="grid gap-2 lg:grid-cols-2">
          <div className="rounded-md border border-border/70 bg-card p-2.5 shadow-sm">
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Collection</p>
                <h2 className="mt-0.5 text-sm font-semibold text-foreground">Revenue pulse</h2>
              </div>
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><CircleDollarSign className="h-4 w-4" /></div>
            </div>
            <div className="mt-2 grid grid-cols-3 gap-2">
              <div><p className="text-[9px] text-muted-foreground">Today</p><p className="mt-0.5 text-xs font-semibold tabular-nums">{peso(metrics?.today_revenue ?? 0)}</p></div>
              <div><p className="text-[9px] text-muted-foreground">This month</p><p className="mt-0.5 text-xs font-semibold tabular-nums">{peso(metrics?.monthly_revenue ?? 0)}</p></div>
              <div><p className="text-[9px] text-muted-foreground">Overdue</p><p className="mt-0.5 text-xs font-semibold tabular-nums text-rose-600 dark:text-rose-400">{metrics?.overdue_invoices ?? 0}</p></div>
            </div>
            <div className="mt-2">
              <div className="mb-1 flex items-center justify-between text-xs"><span className="text-muted-foreground">Collection rate</span><span className="font-bold tabular-nums">{collectionRate}%</span></div>
              <div className="h-2 overflow-hidden rounded-full bg-secondary"><div className="h-full rounded-full bg-gradient-to-r from-cyan-500 to-emerald-500 transition-all duration-700" style={{ width: `${collectionRate}%` }} /></div>
            </div>
          </div>

          <div className="rounded-md border border-border/70 bg-card p-2.5 shadow-sm">
            <div className="flex items-start justify-between gap-4">
              <div><p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Billing summary</p><h2 className="mt-0.5 text-sm font-semibold text-foreground">Account position</h2></div>
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary"><Gauge className="h-4 w-4" /></div>
            </div>
            <div className="mt-1.5 divide-y divide-border/70">
              {[
                ['Total billed', peso(metrics?.total_billed ?? 0)],
                ['Paid', peso(metrics?.total_paid ?? 0)],
                ['Partial', peso(metrics?.partial_paid ?? 0)],
                ['Outstanding', peso(metrics?.collectible ?? 0)],
              ].map(([label, value]) => <div key={label} className="flex items-center justify-between py-1 text-[11px]"><span className="text-muted-foreground">{label}</span><span className="font-semibold tabular-nums text-foreground">{value}</span></div>)}
            </div>
          </div>
        </section>}

        {collector && <section className="order-last overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
          <div className="flex flex-col gap-3 border-b border-border/70 p-4 md:flex-row md:items-center md:justify-between">
            <div><p className="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">Collection workspace</p><h2 className="mt-1 text-lg font-semibold text-foreground">Search client & status</h2><p className="mt-1 text-sm text-muted-foreground">View account status, capture an installation point, request a speed change, or create an early payment invoice. Status changes remain administrator-only.</p></div>
            <form onSubmit={(event) => { event.preventDefault(); void fetchCollectorClients(collectorSearch); }} className="relative w-full md:w-80"><Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" /><input value={collectorSearch} onChange={(event) => setCollectorSearch(event.target.value)} placeholder="Client name, account, address" className="h-9 w-full rounded-lg border border-input bg-background pl-8 pr-16 text-xs outline-none focus:border-primary focus:ring-2 focus:ring-primary/15" /><button className="absolute right-1 top-1 rounded-md bg-primary px-2 py-1 text-xs font-semibold text-primary-foreground">Find</button></form>
          </div>
          <div className="overflow-x-auto"><table className="w-full min-w-[820px] text-left text-sm"><thead className="bg-muted/45 text-[10px] font-semibold uppercase tracking-[0.1em] text-muted-foreground"><tr><th className="px-4 py-3">Client</th><th className="px-4 py-3">Address</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Collector actions</th></tr></thead><tbody className="divide-y divide-border/70">{collectorClients.map((client) => <tr key={client.id}><td className="px-4 py-3"><p className="font-semibold text-foreground">{client.full_name}</p><p className="text-xs text-muted-foreground">{client.account_number} · {client.contact_number || 'No contact'}</p></td><td className="px-4 py-3 text-xs text-muted-foreground">{client.address || 'Not recorded'}<button type="button" disabled={collectorActionBusy === client.id} onClick={() => void updateCollectorLocation(client)} className="mt-1 block text-primary hover:underline">{client.gps_coordinates ? 'Update coordinates' : 'Add coordinates'}</button></td><td className="px-4 py-3"><span className="inline-flex rounded-full bg-muted px-2 py-1 text-xs font-semibold capitalize text-foreground">{client.status}</span><p className="mt-1 text-[10px] text-muted-foreground">View only</p></td><td className="px-4 py-3"><div className="flex min-w-[250px] flex-wrap gap-2"><button type="button" disabled={collectorActionBusy === client.id} onClick={() => void createEarlyInvoice(client)} className="rounded-lg bg-primary px-2.5 py-1.5 text-xs font-semibold text-primary-foreground disabled:opacity-60">Create early invoice</button><select value={selectedPlans[client.id] || ''} onChange={(event) => setSelectedPlans((current) => ({ ...current, [client.id]: event.target.value }))} className="rounded-lg border border-input bg-background px-2 py-1.5 text-xs"><option value="">Request speed change…</option>{planOptions.filter((plan) => plan.id !== client.service_plan?.id).map((plan) => <option value={plan.id} key={plan.id}>{plan.download_speed}/{plan.upload_speed} Mbps</option>)}</select><button type="button" disabled={collectorActionBusy === client.id} onClick={() => void requestPlanChange(client)} className="rounded-lg border border-primary/30 px-2.5 py-1.5 text-xs font-semibold text-primary disabled:opacity-60">Send request</button></div></td></tr>)}{collectorClients.length === 0 && <tr><td colSpan={4} className="px-5 py-10 text-center text-sm text-muted-foreground">No matching clients found.</td></tr>}</tbody></table></div>
        </section>}

        {!collector && <section className="order-last rounded-2xl border border-border/70 bg-card p-5 shadow-sm">
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
        </section>}

        {!collector && <section className="overflow-hidden rounded-xl border border-border/70 bg-card shadow-sm">
          <div className="flex flex-col gap-2 border-b border-border/70 p-3 md:flex-row md:items-center md:justify-between">
            <div>
              <div className="flex items-center gap-1.5"><Radio className="h-3.5 w-3.5 text-primary" /><h2 className="text-sm font-semibold text-foreground">Live queue & lease monitor</h2></div>
              <p className="mt-1 text-sm text-muted-foreground">Live Simple Queue traffic · polls MikroTik every 5 seconds without reloading this page{monitorUpdatedAt ? ` · last checked ${new Date(monitorUpdatedAt).toLocaleTimeString()}` : ''}{monitorPolling ? ' · checking…' : ''}.</p>
            </div>
            <label className="relative block md:w-60"><Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search clients" className="h-8 w-full rounded-lg border border-input bg-background pl-8 pr-2 text-xs outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15" /></label>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-left text-sm">
              <thead className="bg-muted/45 text-[9px] font-semibold uppercase tracking-[0.1em] text-muted-foreground"><tr><th className="px-3 py-2">Client</th><th className="px-3 py-2">Lease</th><th className="px-3 py-2">Queue</th><th className="px-3 py-2">Plan</th><th className="px-3 py-2">Traffic</th><th className="px-3 py-2">Status</th></tr></thead>
              <tbody className="divide-y divide-border/70">
                {loading ? <tr><td colSpan={6} className="px-5 py-12 text-center text-muted-foreground">Loading client monitor…</td></tr> : customers.length === 0 ? <tr><td colSpan={6} className="px-5 py-12 text-center text-muted-foreground">No matched DHCP client leases yet. Sync a router to populate this monitor.</td></tr> : customers.map((customer) => {
                  const theme = statusTheme(customer.customer_status);
                  const StatusIcon = theme.Icon;
                  return <tr key={customer.customer_id} className="transition-colors hover:bg-muted/35"><td className="px-3 py-2 text-xs font-medium text-foreground">{customer.full_name}</td><td className="px-3 py-2 text-xs text-muted-foreground"><p>{customer.ip_address}</p><p className="mt-0.5 text-[10px] capitalize">{customer.lease_status}</p></td><td className="px-3 py-2 text-muted-foreground"><p className="font-mono text-[10px]">{customer.queue_name}</p><p className={`mt-0.5 text-[10px] ${customer.queue_found ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'}`}>{customer.queue_found ? 'Queue found' : 'Awaiting queue sync'}</p></td><td className="px-3 py-2 text-[10px] text-muted-foreground">{customer.service_plan ? `${customer.service_plan.name} · ${customer.service_plan.download_speed}/${customer.service_plan.upload_speed} Mbps` : 'No plan'}</td><td className="px-3 py-2 text-muted-foreground"><QueueTraffic customer={customer} history={trafficHistory[customer.customer_id] ?? []} /></td><td className="px-3 py-2"><span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${theme.className}`}><StatusIcon className="h-3 w-3" />{theme.label}</span></td></tr>;
                })}
              </tbody>
            </table>
          </div>
        </section>}

        {collector && <section className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
          <div className="flex flex-col gap-3 border-b border-border/70 p-4 md:flex-row md:items-center md:justify-between">
            <div><div className="flex items-center gap-2"><MapPin className="h-4 w-4 text-primary" /><h2 className="font-semibold text-foreground">Client location map</h2></div><p className="mt-1 text-sm text-muted-foreground">Search a client, select their pin, then open Google Maps for directions.</p></div>
            <label className="relative block md:w-72"><Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" /><input value={locationQuery} onChange={(event) => setLocationQuery(event.target.value)} placeholder="Search client name or account" className="h-9 w-full rounded-lg border border-input bg-background pl-8 pr-2 text-xs outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15" /></label>
          </div>
          <div className="grid lg:grid-cols-[minmax(0,1.6fr)_minmax(240px,0.9fr)]">
            <div className="min-h-[360px] bg-muted/30">
              {selectedLocation ? <iframe title={`Google map for ${selectedLocation.full_name}`} className="h-[360px] w-full border-0" src={`https://www.google.com/maps?q=${selectedLocation.latitude},${selectedLocation.longitude}&z=16&output=embed`} /> : <div className="flex h-[360px] items-center justify-center p-6 text-center text-sm text-muted-foreground">No customer coordinates are available yet. Add coordinates in the customer profile to place a client on this map.</div>}
            </div>
            <div className="max-h-[360px] overflow-y-auto border-t border-border/70 lg:border-l lg:border-t-0">
              {locationMatches.length ? locationMatches.map((location) => <button type="button" key={location.id} onClick={() => setSelectedLocation(location)} className={`flex w-full items-start gap-3 border-b border-border/70 p-3 text-left transition hover:bg-muted/60 ${selectedLocation?.id === location.id ? 'bg-primary/5' : ''}`}><span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><MapPin className="h-4 w-4" /></span><span className="min-w-0 flex-1"><span className="block truncate text-sm font-semibold text-foreground">{location.full_name}</span><span className="block truncate text-xs text-muted-foreground">{location.account_number} · {location.address || 'Address not recorded'}</span></span><span onClick={(event) => { event.stopPropagation(); openNavigation(location); }} className="rounded-md p-1.5 text-primary hover:bg-primary/10" role="link" aria-label={`Navigate to ${location.full_name}`}><Navigation className="h-4 w-4" /></span></button>) : <p className="p-6 text-center text-sm text-muted-foreground">No client location matches this search.</p>}
            </div>
          </div>
          {selectedLocation && <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/70 p-3 text-sm"><span className="text-muted-foreground">Selected: <b className="text-foreground">{selectedLocation.full_name}</b> · {selectedLocation.latitude.toFixed(6)}, {selectedLocation.longitude.toFixed(6)}</span><button type="button" onClick={() => openNavigation(selectedLocation)} className="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-primary-foreground"><Navigation className="h-3.5 w-3.5" />Open Google Maps</button></div>}
        </section>}
      </div>
    </DashboardLayout>
  );
};

export default NewDashboardPage;
