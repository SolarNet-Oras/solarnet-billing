import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { unregisteredLeaseService, type UnregisteredLease } from '@/services/unregisteredLeaseService';
import { routerService, type Router } from '@/services/routerService';
import { Wifi, RefreshCw, UserPlus, Router as RouterIcon, Tag, Gauge, MapPin } from 'lucide-react';

type Tab = 'static' | 'dynamic';

const UnregisteredLeasesPage: React.FC = () => {
  const navigate = useNavigate();
  const [tab, setTab] = useState<Tab>('static');
  const [staticLeases, setStaticLeases] = useState<UnregisteredLease[]>([]);
  const [dynamicLeases, setDynamicLeases] = useState<UnregisteredLease[]>([]);
  const [routers, setRouters] = useState<Router[]>([]);
  const [loading, setLoading] = useState<boolean>(false);
  const [syncing, setSyncing] = useState<boolean>(false);
  const [registeringId, setRegisteringId] = useState<string | null>(null);
  const [error, setError] = useState<string>('');
  const [notice, setNotice] = useState<string>('');

  useEffect(() => {
    void loadAll();
  }, []);

  const loadAll = async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const [s, d, r] = await Promise.all([
        unregisteredLeaseService.listStaticCommented(),
        unregisteredLeaseService.listDynamic(),
        routerService.getAll().catch(() => [] as Router[]),
      ]);
      setStaticLeases(s);
      setDynamicLeases(d);
      setRouters(r);
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

  const handleQuickRegister = async (lease: UnregisteredLease): Promise<void> => {
    setRegisteringId(lease.id);
    setError('');
    setNotice('');
    try {
      const res = await unregisteredLeaseService.quickRegister(lease.id, {
        full_name: lease.comment || undefined,
        service_plan_id: lease.suggested_plan?.id,
        monthly_fee: lease.suggested_plan?.price,
      });
      setNotice(res.message || 'Client registered.');
      setStaticLeases((prev) => prev.filter((l) => l.id !== lease.id));
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to register client');
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

        {/* Tabs */}
        <div className="flex gap-1 border-b border-border">
          <TabButton
            active={tab === 'static'}
            onClick={() => setTab('static')}
            label={`Static + Comment`}
            count={staticLeases.length}
            testId="tab-static"
          />
          <TabButton
            active={tab === 'dynamic'}
            onClick={() => setTab('dynamic')}
            label={`Dynamic / Manual`}
            count={dynamicLeases.length}
            testId="tab-dynamic"
          />
        </div>

        {/* Body */}
        {loading ? (
          <div className="p-12 text-center text-muted-foreground">Loading leases…</div>
        ) : tab === 'static' ? (
          <StaticLeasesTable
            leases={staticLeases}
            routerName={routerName}
            onRegister={handleQuickRegister}
            registeringId={registeringId}
          />
        ) : (
          <DynamicLeasesTable
            leases={dynamicLeases}
            routerName={routerName}
            onAdd={handleManualAdd}
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
    <div className="overflow-x-auto bg-card border border-border rounded-lg">
      <table className="w-full text-sm">
        <thead className="bg-secondary/50 text-muted-foreground uppercase text-xs tracking-wider">
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
  );
};

// -----------------------------------------------------------------------------
// Dynamic tab (manual add)
// -----------------------------------------------------------------------------
const DynamicLeasesTable: React.FC<{
  leases: UnregisteredLease[];
  routerName: (id: string) => string;
  onAdd: (lease: UnregisteredLease) => void;
}> = ({ leases, routerName, onAdd }) => {
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
    <div className="overflow-x-auto bg-card border border-border rounded-lg">
      <table className="w-full text-sm">
        <thead className="bg-secondary/50 text-muted-foreground uppercase text-xs tracking-wider">
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
                <button
                  type="button"
                  onClick={() => onAdd(lease)}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 border border-primary text-primary rounded-md hover:bg-primary/10 transition"
                  data-testid={`manual-add-btn-${lease.id}`}
                >
                  <UserPlus className="w-4 h-4" />
                  Add as Client
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
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

export default UnregisteredLeasesPage;
