import { useEffect, useMemo, useState } from 'react';
import { Activity, Network, Plus, RefreshCw, Server, ShieldCheck, Wifi, WifiOff } from 'lucide-react';
import { routerService, type Router, type CreateRouterData } from '@/services/routerService';
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

export function MikroTikRouters() {
  const [routers, setRouters] = useState<Router[]>([]);
  const [loading, setLoading] = useState(true);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingRouter, setEditingRouter] = useState<Router | null>(null);
  const [refreshing, setRefreshing] = useState(false);

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

  const online = routers.filter((router) => router.connection_status === 'online').length;
  const offline = routers.filter((router) => router.connection_status === 'offline').length;
  const unknown = routers.length - online - offline;
  const active = routers.filter((router) => router.is_active).length;
  const latestSync = useMemo(() => routers.map((router) => router.last_sync_at).filter(Boolean).sort().at(-1), [routers]);
  const lastSyncLabel = latestSync ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(latestSync)) : 'No sync recorded';

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
        <MetricCard label="Billing protection" value={active} helper="Active routers" tone="sky" icon={ShieldCheck} />
      </div>

      <div className="mt-5 grid gap-4 xl:grid-cols-[1.05fr_1.35fr]">
        <article className="rounded-2xl border border-slate-700/80 bg-slate-950/65 p-5">
          <div className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-sky-500/10 text-sky-300"><Activity className="h-5 w-5" /></span><div><h3 className="font-semibold text-white">Connection monitoring</h3><p className="text-xs text-slate-400">Status saved from router connection tests and syncs.</p></div></div>
          <div className="mt-5 grid grid-cols-3 gap-3 text-center"><div><p className="text-xl font-bold text-emerald-300">{online}</p><p className="text-xs text-slate-400">Online</p></div><div><p className="text-xl font-bold text-orange-300">{offline}</p><p className="text-xs text-slate-400">Offline</p></div><div><p className="text-xl font-bold text-slate-300">{unknown}</p><p className="text-xs text-slate-400">Unverified</p></div></div>
          <p className="mt-5 border-t border-slate-800 pt-4 text-xs text-slate-400">Last router sync: <span className="font-medium text-slate-200">{lastSyncLabel}</span></p>
        </article>
        <article className="rounded-2xl border border-emerald-500/25 bg-gradient-to-r from-emerald-500/10 via-slate-950/70 to-slate-950/70 p-5">
          <div className="flex items-start gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl border border-emerald-400/30 bg-emerald-500/10 text-emerald-300"><Network className="h-5 w-5" /></span><div><h3 className="font-semibold text-emerald-200">Payment access protection</h3><p className="mt-1 text-sm text-slate-400">Use each router’s existing shield actions below to audit, install, verify, or remove only SolarNet billing rules. Unrelated MikroTik configuration is not touched.</p></div></div>
          <div className="mt-5 inline-flex rounded-full border border-emerald-400/25 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-200">Safe, router-by-router controls</div>
        </article>
      </div>

      <div className="mt-6 rounded-2xl border border-cyan-400/25 bg-slate-950/75 p-1 shadow-[0_0_35px_-20px_rgba(34,211,238,0.9)]">
        <div className="flex items-center justify-between gap-3 px-4 py-4"><div><h3 className="font-semibold text-cyan-200">Router list</h3><p className="text-xs text-slate-400">All existing MikroTik actions remain available for every router.</p></div><span className="rounded-full border border-emerald-400/25 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-200">Secure connection</span></div>
        <div className="rounded-xl bg-background text-foreground"><RouterList routers={routers} onEdit={(router) => { setEditingRouter(router); setIsModalOpen(true); }} onDelete={async (id) => { if (confirm('Are you sure you want to delete this router?')) { await routerService.delete(id); await loadRouters(); } }} onTestConnection={() => void loadRouters(true)} onSync={() => void loadRouters(true)} /></div>
      </div>

      <RouterFormModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} onSave={handleSave} router={editingRouter} />
    </section>
  );
}
