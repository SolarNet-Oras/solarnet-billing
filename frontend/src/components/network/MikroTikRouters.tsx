import { useEffect, useMemo, useState } from 'react';
import { Activity, AlertTriangle, Plus, RefreshCw, ScanSearch, Server, ShieldCheck, Wifi, WifiOff } from 'lucide-react';
import { routerService, type Router, type CreateRouterData, type RouterMonitoringSnapshot, type RouterThreatObservation } from '@/services/routerService';
import { RouterList } from './RouterList';
import { RouterFormModal } from './RouterFormModal';

function MetricCard({ label, value, helper, tone, icon: Icon }: { label: string; value: string | number; helper: string; tone: 'violet' | 'emerald' | 'orange' | 'sky'; icon: typeof Server }) {
  const tones = {
    violet: 'border-violet-400/30 bg-violet-500/10 text-violet-300',
    emerald: 'border-emerald-400/30 bg-emerald-500/10 text-emerald-300',
    orange: 'border-orange-400/30 bg-orange-500/10 text-orange-300',
    sky: 'border-sky-400/30 bg-sky-500/10 text-sky-300',
  };

  return (
    <article className="relative overflow-hidden rounded-2xl border border-slate-700/70 bg-slate-950/70 p-5 shadow-[0_18px_45px_-30px_rgba(56,189,248,0.65)] backdrop-blur">
      <div className={`absolute -right-8 -top-8 h-24 w-24 rounded-full blur-2xl ${tone === 'violet' ? 'bg-violet-500/30' : tone === 'emerald' ? 'bg-emerald-500/25' : tone === 'orange' ? 'bg-orange-500/25' : 'bg-sky-500/25'}`} />
      <div className="relative flex items-center justify-between gap-4">
        <div className={`grid h-12 w-12 place-items-center rounded-xl border ${tones[tone]}`}><Icon className="h-6 w-6" /></div>
        <span className={`rounded-full border px-2.5 py-1 text-xs font-semibold ${tones[tone]}`}>{helper}</span>
      </div>
      <p className="relative mt-5 text-sm font-medium text-slate-400">{label}</p>
      <p className="relative mt-1 text-3xl font-bold tracking-tight text-white">{value}</p>
    </article>
  );
}

const formatRate = (bps: number | null): string => {
  if (bps === null) return 'Sampling…';
  if (bps < 1_000) return `${bps} bps`;
  if (bps < 1_000_000) return `${(bps / 1_000).toFixed(1)} Kbps`;
  return `${(bps / 1_000_000).toFixed(1)} Mbps`;
};

export function MikroTikRouters() {
  const [routers, setRouters] = useState<Router[]>([]);
  const [loading, setLoading] = useState(true);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingRouter, setEditingRouter] = useState<Router | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [monitoring, setMonitoring] = useState<Record<string, RouterMonitoringSnapshot>>({});
  const [threatObservations, setThreatObservations] = useState<Record<string, RouterThreatObservation[]>>({});
  const [scanningRouterId, setScanningRouterId] = useState<string | null>(null);
  const [reviewingObservationId, setReviewingObservationId] = useState<string | null>(null);

  const loadRouters = async (showRefresh = false) => {
    try {
      if (showRefresh) setRefreshing(true); else setLoading(true);
      setRouters(await routerService.getAll());
    } catch (error) {
      console.error('Failed to load routers:', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => { void loadRouters(); }, []);

  useEffect(() => {
    let disposed = false;
    const poll = async () => {
      const targets = routers.filter((router) => router.is_active && router.connection_status !== 'offline');
      const results = await Promise.all(targets.map(async (router) => {
        try { return [router.id, await routerService.monitoring(router.id)] as const; }
        catch { return null; }
      }));
      if (!disposed) setMonitoring((current) => ({ ...current, ...Object.fromEntries(results.filter((result): result is readonly [string, RouterMonitoringSnapshot] => result !== null)) }));
    };
    if (routers.length) {
      void poll();
      const interval = window.setInterval(() => void poll(), 5_000);
      return () => { disposed = true; window.clearInterval(interval); };
    }
    return () => { disposed = true; };
  }, [routers]);

  const loadThreatObservations = async (routerList = routers) => {
    const results = await Promise.all(routerList.map(async (router) => {
      try { return [router.id, await routerService.threatObservations(router.id)] as const; }
      catch { return null; }
    }));
    setThreatObservations((current) => ({ ...current, ...Object.fromEntries(results.filter((result): result is readonly [string, RouterThreatObservation[]] => result !== null)) }));
  };

  useEffect(() => {
    if (routers.length) void loadThreatObservations();
  }, [routers]);

  const online = routers.filter((router) => router.connection_status === 'online').length;
  const offline = routers.filter((router) => router.connection_status === 'offline').length;
  const unknown = routers.length - online - offline;
  const active = routers.filter((router) => router.is_active).length;
  const latestSync = useMemo(() => routers.map((router) => router.last_sync_at).filter(Boolean).sort().at(-1), [routers]);
  const lastSyncLabel = latestSync ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(latestSync)) : 'No sync recorded';
  const samples = Object.values(monitoring);
  const rxBps = samples.reduce((total, sample) => total + (sample.rx_bps ?? 0), 0);
  const txBps = samples.reduce((total, sample) => total + (sample.tx_bps ?? 0), 0);
  const hasTrafficSample = samples.some((sample) => sample.traffic_sampled);
  const protectedRouters = samples.filter((sample) => sample.threat_status === 'protected').length;
  const threatSignals = samples.reduce((total, sample) => total + sample.threat_signal_rules + sample.threat_address_list_entries, 0);
  const pendingThreats = Object.values(threatObservations).flat().filter((observation) => observation.status === 'pending');

  const scanThreatFeed = async (router: Router) => {
    try {
      setScanningRouterId(router.id);
      const result = await routerService.scanThreatFeed(router.id);
      await loadThreatObservations([router]);
      window.alert(result.message);
    } catch (error: any) {
      window.alert(error?.response?.data?.message || 'Threat scan failed. No RouterOS firewall change was made.');
    } finally {
      setScanningRouterId(null);
    }
  };

  const reviewThreat = async (router: Router, observation: RouterThreatObservation, decision: 'approve_block' | 'dismiss') => {
    const action = decision === 'approve_block' ? 'block this IP on this router' : 'dismiss this candidate';
    if (!window.confirm(`Confirm: ${action}?\n\n${observation.remote_ip}\nFeed: ${observation.feed_name}\n\n${decision === 'approve_block' ? 'This will add only SolarNet-owned threat-feed firewall entries to this router.' : 'No RouterOS firewall change will be made.'}`)) return;
    try {
      setReviewingObservationId(observation.id);
      const result = await routerService.reviewThreatObservation(router.id, observation.id, decision);
      await loadThreatObservations([router]);
      window.alert(result.message);
    } catch (error: any) {
      window.alert(error?.response?.data?.message || 'Could not review this threat candidate.');
    } finally {
      setReviewingObservationId(null);
    }
  };

  const handleSave = async (data: CreateRouterData) => {
    if (editingRouter) await routerService.update(editingRouter.id, data);
    else await routerService.create(data);
    setIsModalOpen(false);
    await loadRouters();
  };

  if (loading) return <div className="flex items-center justify-center p-12"><div className="h-12 w-12 animate-spin rounded-full border-2 border-sky-400 border-t-transparent" /></div>;

  return (
    <section className="relative isolate overflow-hidden rounded-3xl border border-slate-700 bg-[#020817] p-4 text-slate-100 shadow-2xl sm:p-6">
      <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_50%_-15%,rgba(109,40,217,0.35),transparent_35%),radial-gradient(circle_at_10%_20%,rgba(14,165,233,0.16),transparent_30%)]" />
      <div className="pointer-events-none absolute inset-x-0 top-28 -z-10 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent" />

      <header className="flex flex-col gap-5 border-b border-slate-800 pb-6 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">Network operations</p>
          <h2 className="mt-2 text-3xl font-bold tracking-tight text-white">MikroTik Routers</h2>
          <p className="mt-2 text-sm text-slate-400">Live configuration status and safe billing controls for your connected RouterOS devices.</p>
        </div>
        <div className="flex flex-wrap gap-3">
          <button onClick={() => void loadRouters(true)} disabled={refreshing} className="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-slate-200 transition hover:border-cyan-400/60 hover:text-white disabled:opacity-50">
            <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} /> Refresh status
          </button>
          <button onClick={() => { setEditingRouter(null); setIsModalOpen(true); }} className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-sky-500 to-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-sky-500/20 transition hover:brightness-110">
            <Plus className="h-4 w-4" /> Add router
          </button>
        </div>
      </header>

      <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard label="Total routers" value={routers.length} helper={`${active} active`} tone="violet" icon={Server} />
        <MetricCard label="Online" value={online} helper={routers.length ? `${Math.round((online / routers.length) * 100)}% reachable` : 'No devices'} tone="emerald" icon={Wifi} />
        <MetricCard label="Offline" value={offline} helper={unknown ? `${unknown} unverified` : 'No unknown'} tone="orange" icon={WifiOff} />
        <MetricCard label="Live data traffic" value={hasTrafficSample ? formatRate(rxBps + txBps) : 'Sampling…'} helper={hasTrafficSample ? `RX ${formatRate(rxBps)} · TX ${formatRate(txBps)}` : '5s read-only sampling'} tone="sky" icon={Activity} />
      </div>

      <div className="mt-5 grid gap-4 xl:grid-cols-[1.05fr_1.35fr]">
        <article className="rounded-2xl border border-slate-700/80 bg-slate-950/65 p-5">
          <div className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-sky-500/10 text-sky-300"><Activity className="h-5 w-5" /></span><div><h3 className="font-semibold text-white">All-router data traffic</h3><p className="text-xs text-slate-400">Combined total only. Individual router traffic is shown below.</p></div></div>
          <div className="mt-5 grid grid-cols-2 gap-3 text-center"><div className="rounded-xl bg-sky-500/10 p-3"><p className="text-xl font-bold text-sky-300">{formatRate(hasTrafficSample ? rxBps : null)}</p><p className="text-xs text-slate-400">RX traffic</p></div><div className="rounded-xl bg-violet-500/10 p-3"><p className="text-xl font-bold text-violet-300">{formatRate(hasTrafficSample ? txBps : null)}</p><p className="text-xs text-slate-400">TX traffic</p></div></div>
          <p className="mt-5 border-t border-slate-800 pt-4 text-xs text-slate-400">Last router sync: <span className="font-medium text-slate-200">{lastSyncLabel}</span></p>
        </article>
        <article className="rounded-2xl border border-emerald-500/25 bg-gradient-to-r from-emerald-500/10 via-slate-950/70 to-slate-950/70 p-5">
          <div className="flex items-start gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl border border-emerald-400/30 bg-emerald-500/10 text-emerald-300"><ShieldCheck className="h-5 w-5" /></span><div><h3 className="font-semibold text-emerald-200">Virus & threat monitor</h3><p className="mt-1 text-sm text-slate-400">RouterOS firewall signals plus optional threat-feed connection scans. This is network detection, not endpoint antivirus.</p></div></div>
          <div className="mt-5 flex flex-wrap gap-2"><span className="rounded-full border border-emerald-400/25 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-200">{protectedRouters}/{samples.length || active} routers protected</span><span className="rounded-full border border-violet-400/25 bg-violet-500/10 px-3 py-1.5 text-xs font-semibold text-violet-200">{threatSignals} firewall signals</span><span className="rounded-full border border-amber-400/25 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-200">{pendingThreats.length} pending review</span></div>
        </article>
      </div>

      <section className="mt-5 rounded-2xl border border-sky-400/20 bg-slate-950/70 p-5">
        <div className="flex items-center justify-between gap-3"><div><h3 className="font-semibold text-sky-200">Traffic by MikroTik router</h3><p className="text-xs text-slate-400">Each card is a separate RouterOS read. RX/TX counters refresh every 5 seconds.</p></div><span className="text-xs text-slate-500">No router configuration changes</span></div>
        <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {routers.map((router) => {
            const snapshot = monitoring[router.id];
            return <article key={router.id} className="rounded-xl border border-slate-800 bg-slate-900/70 p-4"><div className="flex items-center justify-between gap-3"><div><p className="font-semibold text-white">{router.name}</p><p className="text-xs text-slate-400">{router.host}:{router.port}</p></div><span className={`rounded-full px-2 py-1 text-xs font-semibold ${router.connection_status === 'online' ? 'bg-emerald-500/15 text-emerald-300' : router.connection_status === 'offline' ? 'bg-red-500/15 text-red-300' : 'bg-slate-700 text-slate-300'}`}>{router.connection_status}</span></div><div className="mt-4 grid grid-cols-2 gap-2"><div className="rounded-lg bg-sky-500/10 p-2.5"><p className="text-sm font-bold text-sky-300">{formatRate(snapshot?.traffic_sampled ? snapshot.rx_bps : null)}</p><p className="mt-1 text-xs text-slate-400">RX</p></div><div className="rounded-lg bg-violet-500/10 p-2.5"><p className="text-sm font-bold text-violet-300">{formatRate(snapshot?.traffic_sampled ? snapshot.tx_bps : null)}</p><p className="mt-1 text-xs text-slate-400">TX</p></div></div><p className="mt-3 text-xs text-slate-500">{snapshot ? `${snapshot.running_interfaces} running interfaces · CPU ${snapshot.cpu_load}%` : 'Waiting for a read-only monitoring sample…'}</p></article>;
          })}
        </div>
      </section>

      <section className="mt-5 rounded-2xl border border-amber-400/20 bg-slate-950/70 p-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h3 className="flex items-center gap-2 font-semibold text-amber-100"><AlertTriangle className="h-4 w-4 text-amber-300" /> Threat-feed review</h3><p className="mt-1 text-xs text-slate-400">Scan compares current RouterOS connections with the Feodo Tracker botnet/C2 feed. A match is logged first; it is never blocked automatically.</p></div><span className="rounded-full border border-amber-400/25 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-100">Manual approval required</span></div>
        <div className="mt-4 grid gap-3 xl:grid-cols-2">
          {routers.map((router) => {
            const observations = threatObservations[router.id] || [];
            const pending = observations.filter((observation) => observation.status === 'pending');
            return <article key={router.id} className="rounded-xl border border-slate-800 bg-slate-900/70 p-4"><div className="flex flex-wrap items-center justify-between gap-3"><div><p className="font-semibold text-white">{router.name}</p><p className="text-xs text-slate-400">{pending.length} pending candidate{pending.length === 1 ? '' : 's'}</p></div><button type="button" disabled={scanningRouterId === router.id || router.connection_status === 'offline'} onClick={() => void scanThreatFeed(router)} className="inline-flex items-center gap-2 rounded-lg border border-amber-400/30 bg-amber-500/10 px-3 py-2 text-xs font-semibold text-amber-100 transition hover:bg-amber-500/20 disabled:cursor-not-allowed disabled:opacity-50"><ScanSearch className={`h-4 w-4 ${scanningRouterId === router.id ? 'animate-pulse' : ''}`} />{scanningRouterId === router.id ? 'Scanning...' : 'Scan connections'}</button></div>
              {pending.length === 0 ? <p className="mt-4 text-xs text-slate-500">No pending candidate. A scan is read-only until an administrator chooses Block.</p> : <div className="mt-4 space-y-2">{pending.map((observation) => <div key={observation.id} className="rounded-lg border border-amber-400/15 bg-amber-500/5 p-3"><div className="flex flex-wrap items-center justify-between gap-2"><div><p className="font-mono text-sm font-semibold text-amber-100">{observation.remote_ip}</p><p className="text-xs text-slate-400">{observation.feed_name} · seen as {(observation.connection_directions || []).join(' / ') || 'connection'}</p></div><div className="flex gap-2"><button type="button" disabled={reviewingObservationId === observation.id} onClick={() => void reviewThreat(router, observation, 'dismiss')} className="rounded-md border border-slate-600 px-2.5 py-1.5 text-xs text-slate-300 hover:bg-slate-800 disabled:opacity-50">Dismiss</button><button type="button" disabled={reviewingObservationId === observation.id} onClick={() => void reviewThreat(router, observation, 'approve_block')} className="rounded-md bg-red-500/90 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-red-500 disabled:opacity-50">Block after review</button></div></div></div>)}</div>}
            </article>;
          })}
        </div>
      </section>

      <div className="mt-6 rounded-2xl border border-cyan-400/25 bg-slate-950/75 p-1 shadow-[0_0_35px_-20px_rgba(34,211,238,0.9)]">
        <div className="flex items-center justify-between gap-3 px-4 py-4"><div><h3 className="font-semibold text-cyan-200">Router list</h3><p className="text-xs text-slate-400">All existing MikroTik actions remain available for every router.</p></div><span className="rounded-full border border-emerald-400/25 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-200">Secure connection</span></div>
        <div className="rounded-xl bg-background text-foreground"><RouterList routers={routers} onEdit={(router) => { setEditingRouter(router); setIsModalOpen(true); }} onDelete={async (id) => { if (confirm('Are you sure you want to delete this router?')) { await routerService.delete(id); await loadRouters(); } }} onTestConnection={() => void loadRouters(true)} onSync={() => void loadRouters(true)} /></div>
      </div>

      <RouterFormModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} onSave={handleSave} router={editingRouter} />
    </section>
  );
}
