import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { unregisteredLeaseService, type UnregisteredLease } from '@/services/unregisteredLeaseService';
import { routerService, type Router } from '@/services/routerService';
import { servicePlanService, type ServicePlan } from '@/services/servicePlanService';
import { Wifi, RefreshCw, UserPlus, Router as RouterIcon, Tag, Gauge, MapPin, Search, X } from 'lucide-react';

type Tab = 'static' | 'dynamic';

const UnregisteredLeasesPage: React.FC = () => {
  const navigate = useNavigate();
  const [tab, setTab] = useState<Tab>('static');
  const [staticLeases, setStaticLeases] = useState<UnregisteredLease[]>([]);
  const [dynamicLeases, setDynamicLeases] = useState<UnregisteredLease[]>([]);
  const [routers, setRouters] = useState<Router[]>([]);
  const [plans, setPlans] = useState<ServicePlan[]>([]);
  const [loading, setLoading] = useState<boolean>(false);
  const [syncing, setSyncing] = useState<boolean>(false);
  const [registeringId, setRegisteringId] = useState<string | null>(null);
  const [error, setError] = useState<string>('');
  const [notice, setNotice] = useState<string>('');
  const [modalLease, setModalLease] = useState<UnregisteredLease | null>(null);
  const [search, setSearch] = useState<string>('');

  useEffect(() => {
    void loadAll();
  }, []);

  const loadAll = async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const [s, d, r, p] = await Promise.all([
        unregisteredLeaseService.listStaticCommented(),
        unregisteredLeaseService.listDynamic(),
        routerService.getAll().catch(() => [] as Router[]),
        servicePlanService.getAll().catch(() => [] as ServicePlan[]),
      ]);
      setStaticLeases(s);
      setDynamicLeases(d);
      setRouters(r);
      setPlans(p.filter((pl) => pl.is_active));
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to load DHCP leases');
    } finally {
      setLoading(false);
    }
  };

  const handleSync = async (): Promise<void> => {
    setSyncing(true);
    setError('');
    setNotice('');
    try {
      const result = await unregisteredLeaseService.syncAll();
      setNotice(
        `Synced ${result.total_routers} router${result.total_routers === 1 ? '' : 's'} — ${result.success} succeeded, ${result.failed} failed.`
      );
      await loadAll();
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to sync DHCP leases from routers');
    } finally {
      setSyncing(false);
    }
  };

  const handleQuickRegister = async (
    lease: UnregisteredLease,
    overrides?: { full_name?: string; service_plan_id?: string; monthly_fee?: number },
  ): Promise<void> => {
    setRegisteringId(lease.id);
    setError('');
    setNotice('');
    try {
      const res = await unregisteredLeaseService.quickRegister(lease.id, {
        full_name: overrides?.full_name ?? (lease.comment || undefined),
        service_plan_id: overrides?.service_plan_id ?? lease.suggested_plan?.id,
        monthly_fee: overrides?.monthly_fee ?? lease.suggested_plan?.price,
      });

      // Compose a status line that ALSO surfaces the MikroTik sync outcome —
      // registration can succeed while MikroTik push fails (offline router,
      // API error, etc.) and the admin needs to know.
      const parts: string[] = [res.message || 'Client registered.'];
      const mk = (res as any).mikrotik_sync;
      if (mk) {
        if (mk.success) {
          parts.push(`MikroTik: ${mk.message}`);
        } else if (mk.skipped) {
          parts.push(`MikroTik skipped (${mk.message})`);
        } else {
          // Non-fatal failure — show as a warning inline
          setError(`Registered, but MikroTik sync failed: ${mk.message}`);
        }
      }
      setNotice(parts.join(' · '));
      setStaticLeases((prev) => prev.filter((l) => l.id !== lease.id));
      setDynamicLeases((prev) => prev.filter((l) => l.id !== lease.id));
      setModalLease(null);
    } catch (err: any) {
      // Prefer the backend's structured error over a generic message.
      const payload = err?.response?.data;
      const msg =
        payload?.message ||
        (payload?.errors && Object.values(payload.errors).flat().join(' ')) ||
        err?.message ||
        'Failed to register client';
      setError(msg);
    } finally {
      setRegisteringId(null);
    }
  };

  const handleManualAdd = (lease: UnregisteredLease): void => {
    // Prefill the CreateCustomer form using query string.
    const params = new URLSearchParams({
      mac: lease.mac_address ?? '',
      ip: lease.ip_address ?? '',
      router: lease.router_id,
      hostname: lease.hostname ?? '',
    });
    navigate(`/customers/new?${params.toString()}`);
  };

  const routerName = (id: string): string =>
    routers.find((r) => r.id === id)?.name ?? 'Unknown router';

  const normalizedSearch = search.trim().toLowerCase();
  const filterLeases = (leases: UnregisteredLease[]): UnregisteredLease[] => {
    if (!normalizedSearch) return leases;

    return leases.filter((lease) => [
      lease.mac_address,
      lease.ip_address,
      lease.hostname,
      lease.comment,
      lease.rate_limit,
      lease.status,
      lease.server,
      routerName(lease.router_id),
    ].some((value) => String(value ?? '').toLowerCase().includes(normalizedSearch)));
  };

  const filteredStaticLeases = filterLeases(staticLeases);
  const filteredDynamicLeases = filterLeases(dynamicLeases);

  return (
    <DashboardLayout>
      <div className="max-w-7xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <div className="flex items-center gap-3">
              <div className="w-11 h-11 rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center shadow-lg">
                <Wifi className="w-6 h-6 text-white" />
              </div>
              <div>
                <h1 className="text-3xl font-bold text-foreground">Unregistered Clients</h1>
                <p className="text-muted-foreground mt-0.5">
                  DHCP leases synced from MikroTik that are not yet linked to a customer.
                </p>
              </div>
            </div>
          </div>
          <button
            type="button"
            onClick={handleSync}
            disabled={syncing}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-primary text-primary-foreground hover:opacity-90 transition disabled:opacity-50"
            data-testid="sync-all-leases-btn"
          >
            <RefreshCw className={`w-4 h-4 ${syncing ? 'animate-spin' : ''}`} />
            {syncing ? 'Syncing…' : 'Sync from all routers'}
          </button>
        </div>

        {error && (
          <div
            className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4 text-sm text-red-800 dark:text-red-200"
            data-testid="lease-error"
          >
            {error}
          </div>
        )}
        {notice && (
          <div
            className="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-md p-4 text-sm text-emerald-800 dark:text-emerald-200"
            data-testid="lease-notice"
          >
            {notice}
          </div>
        )}

        <div className="relative max-w-xl" data-testid="unregistered-lease-search">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground pointer-events-none" />
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search MAC, IP, hostname, comment, router, rate limit…"
            className="w-full rounded-lg border border-input bg-background py-2.5 pl-10 pr-10 text-sm text-foreground outline-none transition focus:ring-2 focus:ring-primary"
            data-testid="unregistered-lease-search-input"
          />
          {search && (
            <button
              type="button"
              onClick={() => setSearch('')}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
              aria-label="Clear lease search"
              data-testid="unregistered-lease-search-clear"
            >
              <X className="w-4 h-4" />
            </button>
          )}
          <p className="mt-1.5 text-xs text-muted-foreground">Searches all unregistered lease details from both MikroTik routers.</p>
        </div>

        {/* Tabs */}
        <div className="flex gap-1 border-b border-border">
          <TabButton
            active={tab === 'static'}
            onClick={() => setTab('static')}
            label={`Static + Comment`}
            count={filteredStaticLeases.length}
            testId="tab-static"
          />
          <TabButton
            active={tab === 'dynamic'}
            onClick={() => setTab('dynamic')}
            label={`Dynamic / Manual`}
            count={filteredDynamicLeases.length}
            testId="tab-dynamic"
          />
        </div>

        {/* Body */}
        {loading ? (
          <div className="p-12 text-center text-muted-foreground">Loading leases…</div>
        ) : tab === 'static' ? (
          <StaticLeasesTable
            leases={filteredStaticLeases}
            routerName={routerName}
            onRegister={handleQuickRegister}
            registeringId={registeringId}
          />
        ) : (
          <DynamicLeasesTable
            leases={filteredDynamicLeases}
            routerName={routerName}
            onRegister={(l) => setModalLease(l)}
            onAdd={handleManualAdd}
          />
        )}

        {/* Quick-register modal for dynamic / uncommented leases */}
        {modalLease && (
          <QuickRegisterModal
            lease={modalLease}
            plans={plans}
            busy={registeringId === modalLease.id}
            onClose={() => setModalLease(null)}
            onSubmit={(payload) => handleQuickRegister(modalLease, payload)}
          />
        )}
      </div>
    </DashboardLayout>
  );
};

// -----------------------------------------------------------------------------
// Tab button
// -----------------------------------------------------------------------------
const TabButton: React.FC<{
  active: boolean;
  onClick: () => void;
  label: string;
  count: number;
  testId: string;
}> = ({ active, onClick, label, count, testId }) => (
  <button
    type="button"
    onClick={onClick}
    data-testid={testId}
    className={`px-4 py-2.5 -mb-px border-b-2 text-sm font-medium transition ${
      active
        ? 'border-primary text-primary'
        : 'border-transparent text-muted-foreground hover:text-foreground'
    }`}
  >
    {label}
    <span
      className={`ml-2 inline-flex items-center justify-center min-w-[22px] px-1.5 py-0.5 rounded-full text-xs ${
        active ? 'bg-primary/15 text-primary' : 'bg-secondary text-muted-foreground'
      }`}
    >
      {count}
    </span>
  </button>
);

// -----------------------------------------------------------------------------
// Static + Comment tab
// -----------------------------------------------------------------------------
const StaticLeasesTable: React.FC<{
  leases: UnregisteredLease[];
  routerName: (id: string) => string;
  onRegister: (lease: UnregisteredLease) => void;
  registeringId: string | null;
}> = ({ leases, routerName, onRegister, registeringId }) => {
  if (leases.length === 0) {
    return (
      <EmptyState
        icon={<Tag className="w-8 h-8 text-muted-foreground" />}
        title="No static-with-comment leases pending"
        subtitle="Add a comment on your MikroTik DHCP lease (e.g. the client's name) and set a rate-limit, then click 'Sync from all routers' above."
      />
    );
  }

  return (
    <div className="bg-card border border-border rounded-lg" data-testid="static-leases-container">
      <div className="px-4 py-2.5 border-b border-border text-xs text-muted-foreground">
        Showing <strong className="text-foreground">{leases.length}</strong> lease{leases.length === 1 ? '' : 's'} · scroll to view all
      </div>
      <div className="overflow-x-auto overflow-y-auto max-h-[calc(100vh-360px)]">
        <table className="w-full text-sm">
          <thead className="bg-secondary/50 text-muted-foreground uppercase text-xs tracking-wider sticky top-0 z-10">
            <tr>
              <th className="px-4 py-3 text-left">Client (comment)</th>
              <th className="px-4 py-3 text-left">MAC / IP</th>
              <th className="px-4 py-3 text-left">Rate limit → Plan</th>
              <th className="px-4 py-3 text-left">Router</th>
              <th className="px-4 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            {leases.map((lease) => (
              <tr key={lease.id} className="border-t border-border" data-testid={`static-lease-row-${lease.id}`}>
                <td className="px-4 py-3">
                  <div className="font-medium text-foreground">{lease.comment || '(no comment)'}</div>
                  {lease.hostname && (
                    <div className="text-xs text-muted-foreground">host: {lease.hostname}</div>
                  )}
                </td>
                <td className="px-4 py-3 font-mono text-xs">
                  <div>{lease.mac_address}</div>
                  <div className="text-muted-foreground">{lease.ip_address}</div>
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <Gauge className="w-4 h-4 text-muted-foreground" />
                    <span className="font-mono text-xs">{lease.rate_limit || '—'}</span>
                  </div>
                  {lease.suggested_plan ? (
                    <div className="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                      Matched: {lease.suggested_plan.name} (₱{Number(lease.suggested_plan.price).toFixed(2)}/mo)
                    </div>
                  ) : (
                    <div className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                      No matching plan — will save with no plan.
                    </div>
                  )}
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5 text-muted-foreground">
                    <RouterIcon className="w-4 h-4" />
                    <span>{routerName(lease.router_id)}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-right">
                  <button
                    type="button"
                    onClick={() => onRegister(lease)}
                    disabled={registeringId === lease.id}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-primary text-primary-foreground rounded-md hover:opacity-90 transition disabled:opacity-50"
                    data-testid={`register-lease-btn-${lease.id}`}
                  >
                    <UserPlus className="w-4 h-4" />
                    {registeringId === lease.id ? 'Registering…' : 'Register'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

// -----------------------------------------------------------------------------
// Dynamic tab (manual add)
// -----------------------------------------------------------------------------
const DynamicLeasesTable: React.FC<{
  leases: UnregisteredLease[];
  routerName: (id: string) => string;
  onRegister: (lease: UnregisteredLease) => void;
  onAdd: (lease: UnregisteredLease) => void;
}> = ({ leases, routerName, onRegister, onAdd }) => {
  if (leases.length === 0) {
    return (
      <EmptyState
        icon={<MapPin className="w-8 h-8 text-muted-foreground" />}
        title="No unmatched dynamic leases"
        subtitle="Dynamic MikroTik DHCP leases (or static leases without a comment) will appear here for manual client creation."
      />
    );
  }

  return (
    <div className="bg-card border border-border rounded-lg" data-testid="dynamic-leases-container">
      <div className="px-4 py-2.5 border-b border-border text-xs text-muted-foreground">
        Showing <strong className="text-foreground">{leases.length}</strong> lease{leases.length === 1 ? '' : 's'} · scroll to view all
      </div>
      <div className="overflow-x-auto overflow-y-auto max-h-[calc(100vh-360px)]">
        <table className="w-full text-sm">
          <thead className="bg-secondary/50 text-muted-foreground uppercase text-xs tracking-wider sticky top-0 z-10">
            <tr>
              <th className="px-4 py-3 text-left">Hostname</th>
              <th className="px-4 py-3 text-left">MAC / IP</th>
              <th className="px-4 py-3 text-left">Type</th>
              <th className="px-4 py-3 text-left">Router</th>
              <th className="px-4 py-3 text-left">Last seen</th>
              <th className="px-4 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            {leases.map((lease) => (
              <tr key={lease.id} className="border-t border-border" data-testid={`dynamic-lease-row-${lease.id}`}>
                <td className="px-4 py-3 font-medium text-foreground">
                  {lease.hostname || <span className="text-muted-foreground">(no hostname)</span>}
                  {lease.comment && (
                    <div className="text-xs text-muted-foreground">note: {lease.comment}</div>
                  )}
                </td>
                <td className="px-4 py-3 font-mono text-xs">
                  <div>{lease.mac_address}</div>
                  <div className="text-muted-foreground">{lease.ip_address}</div>
                </td>
                <td className="px-4 py-3">
                  <span
                    className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                      lease.is_dynamic
                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                        : 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200'
                    }`}
                  >
                    {lease.is_dynamic ? 'dynamic' : 'static (no comment)'}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5 text-muted-foreground">
                    <RouterIcon className="w-4 h-4" />
                    <span>{routerName(lease.router_id)}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-muted-foreground">
                  {new Date(lease.last_seen_at).toLocaleString()}
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex items-center gap-2 justify-end">
                    <button
                      type="button"
                      onClick={() => onRegister(lease)}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-primary text-primary-foreground rounded-md hover:opacity-90 transition"
                      data-testid={`quick-register-dynamic-btn-${lease.id}`}
                    >
                      <UserPlus className="w-4 h-4" />
                      Register
                    </button>
                    <button
                      type="button"
                      onClick={() => onAdd(lease)}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 border border-primary text-primary rounded-md hover:bg-primary/10 transition"
                      data-testid={`manual-add-btn-${lease.id}`}
                    >
                      Full form
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

// -----------------------------------------------------------------------------
// Empty state
// -----------------------------------------------------------------------------
const EmptyState: React.FC<{ icon: React.ReactNode; title: string; subtitle: string }> = ({
  icon,
  title,
  subtitle,
}) => (
  <div className="bg-card border border-border rounded-lg p-12 text-center">
    <div className="w-16 h-16 mx-auto mb-4 rounded-full bg-secondary flex items-center justify-center">
      {icon}
    </div>
    <h3 className="text-lg font-semibold text-foreground mb-1">{title}</h3>
    <p className="text-sm text-muted-foreground max-w-md mx-auto">{subtitle}</p>
  </div>
);

// -----------------------------------------------------------------------------
// Quick Register modal (used for dynamic / uncommented leases where we need
// the admin to pick a plan and confirm the client name before pushing to MikroTik).
// -----------------------------------------------------------------------------
const QuickRegisterModal: React.FC<{
  lease: UnregisteredLease;
  plans: ServicePlan[];
  busy: boolean;
  onClose: () => void;
  onSubmit: (payload: { full_name: string; service_plan_id?: string; monthly_fee?: number }) => void;
}> = ({ lease, plans, busy, onClose, onSubmit }) => {
  const [fullName, setFullName] = useState<string>(
    lease.comment || lease.hostname || `Client ${lease.mac_address.slice(-5)}`
  );
  const [planId, setPlanId] = useState<string>(plans[0]?.id ?? '');
  const chosenPlan = plans.find((p) => p.id === planId);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
      onClick={onClose}
      data-testid="quick-register-modal"
    >
      <div
        className="bg-card border border-border rounded-xl shadow-2xl max-w-md w-full p-6 space-y-4"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between">
          <div>
            <h3 className="text-lg font-semibold text-foreground">Register client</h3>
            <p className="text-xs text-muted-foreground mt-1">
              Pick a plan — MikroTik will be updated with comment, made static, and rate-limited to the plan.
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="text-muted-foreground hover:text-foreground"
            data-testid="quick-register-modal-close"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="text-xs text-muted-foreground bg-secondary/50 rounded p-3 font-mono">
          <div>MAC: {lease.mac_address}</div>
          <div>IP: {lease.ip_address}</div>
          <div>Hostname: {lease.hostname || '(none)'}</div>
        </div>

        <div>
          <label className="block text-sm font-medium mb-1.5">Full name</label>
          <input
            type="text"
            value={fullName}
            onChange={(e) => setFullName(e.target.value)}
            className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
            data-testid="quick-register-full-name"
          />
        </div>

        <div>
          <label className="block text-sm font-medium mb-1.5">Service plan</label>
          <select
            value={planId}
            onChange={(e) => setPlanId(e.target.value)}
            className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
            data-testid="quick-register-plan"
          >
            <option value="">— No plan (skip rate-limit) —</option>
            {plans.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name} · {p.download_speed}/{p.upload_speed} Mbps · ₱{Number(p.price).toFixed(2)}
              </option>
            ))}
          </select>
          {chosenPlan && (
            <p className="text-xs text-muted-foreground mt-1">
              MikroTik rate-limit will be set to <span className="font-mono">{chosenPlan.download_speed}M/{chosenPlan.upload_speed}M</span>
            </p>
          )}
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 text-sm rounded-md border border-border hover:bg-secondary"
            data-testid="quick-register-cancel"
          >
            Cancel
          </button>
          <button
            type="button"
            disabled={busy || !fullName.trim()}
            onClick={() =>
              onSubmit({
                full_name: fullName.trim(),
                service_plan_id: planId || undefined,
                monthly_fee: chosenPlan?.price,
              })
            }
            className="inline-flex items-center gap-1.5 px-4 py-2 text-sm bg-primary text-primary-foreground rounded-md hover:opacity-90 disabled:opacity-50"
            data-testid="quick-register-submit"
          >
            <UserPlus className="w-4 h-4" />
            {busy ? 'Registering…' : 'Register + Push to MikroTik'}
          </button>
        </div>
      </div>
    </div>
  );
};

export default UnregisteredLeasesPage;
