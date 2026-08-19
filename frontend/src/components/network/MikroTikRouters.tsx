import { useEffect, useState } from 'react';
import { Plus, RefreshCw, SlidersHorizontal } from 'lucide-react';
import { routerService, type CreateRouterData, type Router } from '@/services/routerService';
import { RouterList } from './RouterList';
import { RouterFormModal } from './RouterFormModal';
import { RouterQosMonitor } from './RouterQosMonitor';
import { NetworkDeviceTopology } from './NetworkDeviceTopology';
import { useAuth } from '@/hooks/useAuth';

export function MikroTikRouters() {
  const { user } = useAuth();
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

  const handleSave = async (data: CreateRouterData) => {
    if (editingRouter) await routerService.update(editingRouter.id, data);
    else await routerService.create(data);
    setIsModalOpen(false);
    await loadRouters();
  };

  const roleNames = (user?.roles || []).map((role) => typeof role === 'string' ? role : role.name);
  const mayManageQos = user?.role === 'super_admin' || user?.role === 'admin' || roleNames.includes('super_admin') || roleNames.includes('admin');

  if (loading) return <div className="flex items-center justify-center p-12"><div className="h-12 w-12 animate-spin rounded-full border-2 border-sky-400 border-t-transparent" /></div>;

  return (
    <section className="relative isolate overflow-hidden rounded-3xl border border-slate-700 bg-[#020817] p-4 text-slate-100 shadow-2xl sm:p-6">
      <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_50%_-15%,rgba(109,40,217,0.35),transparent_35%),radial-gradient(circle_at_10%_20%,rgba(14,165,233,0.16),transparent_30%)]" />
      <div className="pointer-events-none absolute inset-x-0 top-28 -z-10 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent" />

      <header className="flex flex-col gap-4 border-b border-slate-800 pb-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">Network operations</p>
          <h2 className="mt-1 text-2xl font-bold tracking-tight text-white sm:text-3xl">MikroTik command deck</h2>
          <p className="mt-1 text-sm text-slate-400">A visual VPS-to-router command path with the existing billing, DNS, lease, and setup controls preserved.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button onClick={() => void loadRouters(true)} disabled={refreshing} className="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-semibold text-slate-200 transition hover:border-cyan-400/60 hover:text-white disabled:opacity-50">
            <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} /> Refresh routers
          </button>
          <button onClick={() => { setEditingRouter(null); setIsModalOpen(true); }} className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-sky-500 to-violet-600 px-3 py-2 text-sm font-semibold text-white shadow-lg shadow-sky-500/20 transition hover:brightness-110">
            <Plus className="h-4 w-4" /> Add router
          </button>
        </div>
      </header>

      <div className="mt-5"><NetworkDeviceTopology routers={routers} /></div>

      <div className="mt-4 rounded-2xl border border-cyan-400/25 bg-slate-950/75 p-1 shadow-[0_0_35px_-20px_rgba(34,211,238,0.9)]">
        <div className="rounded-xl bg-background text-foreground"><RouterList routers={routers} onEdit={(router) => { setEditingRouter(router); setIsModalOpen(true); }} onDelete={async (id) => { if (confirm('Are you sure you want to delete this router?')) { await routerService.delete(id); await loadRouters(); } }} onTestConnection={() => void loadRouters(true)} onSync={() => void loadRouters(true)} /></div>
      </div>

      {mayManageQos && <details className="mt-4 rounded-xl border border-indigo-400/20 bg-slate-950/70 p-3 text-slate-100">
        <summary className="flex cursor-pointer list-none items-center gap-2 text-sm font-semibold text-indigo-100"><SlidersHorizontal className="h-4 w-4" /> Advanced QoS deployment</summary>
        <p className="mt-2 text-xs leading-5 text-slate-400">Hidden by default to keep the router-rack workspace focused. Opening this section does not change RouterOS; inspect before any deployment.</p>
        <div className="mt-3"><RouterQosMonitor routers={routers} /></div>
      </details>}

      <RouterFormModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} onSave={handleSave} router={editingRouter} />
    </section>
  );
}
