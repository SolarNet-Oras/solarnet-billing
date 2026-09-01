import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { unregisteredLeaseService, type CustomerLinkCandidate, type UnregisteredLease, type UnregisteredLeaseVariant } from '@/services/unregisteredLeaseService';
import { routerService, type Router } from '@/services/routerService';
import { servicePlanService, type ServicePlan } from '@/services/servicePlanService';
import { Wifi, RefreshCw, UserPlus, Router as RouterIcon, Tag, Gauge, MapPin, Search, X, UserCheck, AlertTriangle } from 'lucide-react';

const LeaseCustomerIdentity: React.FC<{ lease: UnregisteredLease }> = ({ lease }) => {
  const identity = lease.known_customer_identity;
  if (!identity) return null;

  if (identity.status === 'ambiguous') {
    return (
      <div className="mt-1.5 rounded-md bg-amber-100 px-2 py-1.5 text-xs text-amber-900 dark:bg-amber-900/35 dark:text-amber-200">
        <div className="flex items-center gap-1 font-semibold">
          <AlertTriangle className="h-3.5 w-3.5" />
          Used by {identity.customer_count} customer profiles — review required
        </div>
        <div className="mt-1 flex flex-wrap gap-x-2 gap-y-0.5">
          {(identity.customers ?? []).map((customer) => (
            <span key={customer.id} className="font-medium">
              {customer.full_name} <span className="font-mono text-[11px]">({customer.account_number})</span>
            </span>
          ))}
        </div>
      </div>
    );
  }

  const customer = identity.customer;
  if (!customer) return null;

  return (
    <div className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-emerald-700 dark:text-emerald-300">
      <UserCheck className="h-3.5 w-3.5 shrink-0" />
      <span className="font-semibold">Used by: {customer.full_name}</span>
      <span className="font-mono text-[11px]">{customer.account_number}</span>
      {!customer.same_router && <span className="rounded bg-amber-100 px-1 py-0.5 text-[10px] font-semibold text-amber-900 dark:bg-amber-900/35 dark:text-amber-200">different router</span>}
    </div>
  );
};

const LeaseActivityBadge: React.FC<{ lease: UnregisteredLease }> = ({ lease }) => {
  const active = lease.is_current && lease.status === 'bound';
  return (
    <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ${active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300'}`}>
      {active ? 'ACTIVE ON ROUTER' : `INACTIVE · ${String(lease.status || 'not current').toUpperCase()}`}
    </span>
  );
};

const UnregisteredLeasesPage: React.FC = () => {
  const navigate = useNavigate();
  // Show every backend-returned lease by default. This prevents valid rows
  // from appearing absent simply because an old tab selection hid their group.
  const [variant, setVariant] = useState<UnregisteredLeaseVariant>('all');
  const [staticLeases, setStaticLeases] = useState<UnregisteredLease[]>([]);
  const [dynamicLeases, setDynamicLeases] = useState<UnregisteredLease[]>([]);
  const [routers, setRouters] = useState<Router[]>([]);
  const [plans, setPlans] = useState<ServicePlan[]>([]);
  const [customerLinkCandidates, setCustomerLinkCandidates] = useState<CustomerLinkCandidate[]>([]);
  const [loading, setLoading] = useState<boolean>(false);
  const [syncing, setSyncing] = useState<boolean>(false);
  const [registeringId, setRegisteringId] = useState<string | null>(null);
  const [error, setError] = useState<string>('');
  const [notice, setNotice] = useState<string>('');
  const [modalLease, setModalLease] = useState<UnregisteredLease | null>(null);
  const [modalMode, setModalMode] = useState<'register' | 'reassign'>('register');
  const [search, setSearch] = useState<string>('');
  const [activityFilter, setActivityFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [lastLeaseListRefresh, setLastLeaseListRefresh] = useState<Date | null>(null);

  useEffect(() => {
    void loadAll();
  }, []);

  const loadAll = async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const [s, d, r, p, customers] = await Promise.all([
        unregisteredLeaseService.listStaticCommented(),
        unregisteredLeaseService.listDynamic(),
        routerService.getAll().catch(() => [] as Router[]),
        servicePlanService.getAll().catch(() => [] as ServicePlan[]),
        unregisteredLeaseService.customerLinkCandidates().catch(() => [] as CustomerLinkCandidate[]),
      ]);
      setStaticLeases(s);
      setDynamicLeases(d);
      setLastLeaseListRefresh(new Date());
      setRouters(r);
      setPlans(p.filter((pl) => pl.is_active));
      setCustomerLinkCandidates(customers);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Failed to load DHCP leases');
    } finally {
      setLoading(false);
    }
  };

  // The backend mirrors all active router lease tables every minute. Refresh
  // only the displayed lists while this page is open so a newly observed,
  // unregistered dynamic or commented lease appears without a manual click.
  const refreshLeaseLists = useCallback(async (): Promise<boolean> => {
    try {
      const [staticRows, dynamicRows] = await Promise.all([
        unregisteredLeaseService.listStaticCommented(),
        unregisteredLeaseService.listDynamic(),
      ]);
      setStaticLeases(staticRows);
      setDynamicLeases(dynamicRows);
      setLastLeaseListRefresh(new Date());
      return true;
    } catch (refreshError) {
      // Keep current rows visible; the next live refresh or manual action can
      // display the router-specific error if it persists.
      console.warn('Unregistered DHCP lease live refresh failed', refreshError);
      return false;
    }
  }, []);

  useEffect(() => {
    const interval = window.setInterval(() => {
      if (!syncing && !registeringId && !modalLease) {
        void refreshLeaseLists();
      }
    }, 30_000);

    return () => window.clearInterval(interval);
  }, [modalLease, refreshLeaseLists, registeringId, syncing]);

  const handleSync = async (): Promise<void> => {
    setSyncing(true);
    setError('');
    setNotice('');
    try {
      const refreshed = await refreshLeaseLists();
      if (!refreshed) {
        setError('Unable to refresh the saved DHCP lease list. The automatic MikroTik mirror continues in the background and will retry within one minute.');
        return;
      }
      // This action intentionally reloads local lease rows only. RouterOS is
      // read by the background one-minute mirror, so the page button cannot
      // time out or make lease/queue changes.
      const result = { total_routers: 0, success: 0, failed: 0, routers: [] as any[] };
      const routerResults = result.routers || [];
      const madeStatic = routerResults.reduce<number>((total: number, router: any) => total + Number(router.static_leases_converted || 0), 0);
      const ownershipComments = routerResults.reduce<number>((total: number, router: any) => total + Number(router.ownership_comments_applied || 0), 0);
      const verifiedStatic = routerResults.reduce<number>((total: number, router: any) => total + Number(router.registered_static_leases_verified || 0), 0);
      const ownershipSummary = ` ${madeStatic} exact customer lease${madeStatic === 1 ? '' : 's'} made static; ${ownershipComments} ownership comment${ownershipComments === 1 ? '' : 's'} applied; ${verifiedStatic} registered static lease${verifiedStatic === 1 ? '' : 's'} verified.`;
      setNotice(
        `Synced ${result.total_routers} router${result.total_routers === 1 ? '' : 's'} — ${result.success} succeeded, ${result.failed} failed.`
          + ownershipSummary
      );
      const failedRouters = routerResults.filter((router: any) => Array.isArray(router.errors) && router.errors.length > 0);
      if (failedRouters.length > 0) {
        const details = failedRouters
          .map((router: any) => `${router.router || 'Unknown router'}: ${router.errors.join(' ')}`)
          .join(' ');
        setError(
          `DHCP refresh completed with issues. ${details} Connection Test checks RouterOS system information only; DHCP access and static-lease/queue updates require additional RouterOS permissions.`
        );
      }
      setNotice('The saved unregistered-lease list is current. MikroTik is mirrored automatically every minute; no manual router sync was started.');
    } catch (err: any) {
      const timedOut = err?.code === 'ECONNABORTED' || /timeout/i.test(String(err?.message || ''));
      setError(
        timedOut
          ? 'The DHCP refresh is still taking longer than three minutes. Do not click Refresh again; wait a minute, reload this page, and review the saved leases. The safe automatic lease mirror retries every minute without browser timeout.'
          : (err?.response?.data?.message || 'Failed to sync DHCP leases from routers')
      );
    } finally {
      setSyncing(false);
    }
  };

  const handleQuickRegister = async (
    lease: UnregisteredLease,
    overrides?: { existing_customer_id?: string; full_name?: string; service_plan_id?: string; monthly_fee?: number; confirm_mac_reassignment?: boolean; confirm_current_client_reassignment?: boolean; confirm_duplicate_mac_resolution?: boolean },
  ): Promise<void> => {
    setRegisteringId(lease.id);
    setError('');
    setNotice('');
    try {
      const res = await unregisteredLeaseService.quickRegister(lease.id, {
        existing_customer_id: overrides?.existing_customer_id,
        full_name: overrides?.full_name ?? (lease.comment || undefined),
        service_plan_id: overrides?.service_plan_id ?? lease.suggested_plan?.id,
        monthly_fee: overrides?.monthly_fee ?? lease.suggested_plan?.price,
        confirm_mac_reassignment: overrides?.confirm_mac_reassignment,
        confirm_current_client_reassignment: overrides?.confirm_current_client_reassignment,
        confirm_duplicate_mac_resolution: overrides?.confirm_duplicate_mac_resolution,
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
      setModalMode('register');
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
    const params = new URLSearchParams({
      mac: lease.mac_address ?? '',
      ip: lease.ip_address ?? '',
      router: lease.router_id,
      hostname: lease.hostname ?? '',
    });
    navigate(`/customers/new?${params.toString()}`);
  };

  const openRegistrationModal = (lease: UnregisteredLease): void => {
    setModalMode('register');
    setModalLease(lease);
  };

  const openReassignmentModal = (lease: UnregisteredLease): void => {
    setModalMode('reassign');
    setModalLease(lease);
  };

  const routerName = (id: string): string =>
    routers.find((r) => r.id === id)?.name ?? 'Unknown router';

  const normalizedSearch = search.trim().toLowerCase();
  const filterLeases = (leases: UnregisteredLease[]): UnregisteredLease[] => {
    return leases.filter((lease) => {
      const active = lease.is_current && lease.status === 'bound';
      if (activityFilter === 'active' && !active) return false;
      if (activityFilter === 'inactive' && active) return false;
      if (!normalizedSearch) return true;
      return [
        lease.mac_address, lease.ip_address, lease.hostname, lease.comment,
        lease.rate_limit, lease.status, lease.server,
        lease.known_customer_identity?.customer?.full_name,
        lease.known_customer_identity?.customer?.account_number,
        routerName(lease.router_id),
      ].some((value) => String(value ?? '').toLowerCase().includes(normalizedSearch));
    });
  };

  const filteredStaticLeases = filterLeases(staticLeases);
  const filteredDynamicLeases = filterLeases(dynamicLeases);
  const allFilteredLeaseCount = filteredStaticLeases.length + filteredDynamicLeases.length;

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
                  Live DHCP leases for review. Customer-owned devices show their identity; an administrator can explicitly move a reused device to another client after confirmation. Router lease state mirrors every minute; this page refreshes every 30 seconds.
                </p>
                {lastLeaseListRefresh && <p className="mt-1 text-xs text-emerald-700 dark:text-emerald-300">Lease list updated {lastLeaseListRefresh.toLocaleTimeString()}.</p>}
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
            {syncing ? 'Refreshing…' : 'Refresh shown leases'}
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
            placeholder="Search client, MAC address, or IP address…"
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
          <p className="mt-1.5 text-xs text-muted-foreground">Searches static and dynamic DHCP leases from both MikroTik routers by client comment, full or partial IP address, MAC, hostname, router, or rate limit.</p>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="font-medium text-muted-foreground">MAC activity:</span>
          {(['all', 'active', 'inactive'] as const).map((state) => (
            <button key={state} type="button" onClick={() => setActivityFilter(state)} className={`rounded-full px-3 py-1.5 font-medium capitalize ${activityFilter === state ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:text-foreground'}`}>
              {state}
            </button>
          ))}
        </div>

        <div className="flex gap-1 border-b border-border">
          <button
            type="button"
            onClick={() => setVariant('all')}
            className={`px-4 py-2.5 -mb-px border-b-2 text-sm font-medium transition ${variant === 'all' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
            data-testid="tab-all"
          >
            All leases <span className="ml-2 inline-flex min-w-[22px] items-center justify-center rounded-full bg-primary/15 px-1.5 py-0.5 text-xs">{allFilteredLeaseCount}</span>
          </button>
          <button
            type="button"
            onClick={() => setVariant('static_commented')}
            className={`px-4 py-2.5 -mb-px border-b-2 text-sm font-medium transition ${variant === 'static_commented' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
            data-testid="tab-static"
          >
            Static + Comment <span className="ml-2 inline-flex min-w-[22px] items-center justify-center rounded-full bg-primary/15 px-1.5 py-0.5 text-xs">{filteredStaticLeases.length}</span>
          </button>
          <button
            type="button"
            onClick={() => setVariant('dynamic')}
            className={`px-4 py-2.5 -mb-px border-b-2 text-sm font-medium transition ${variant === 'dynamic' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'}`}
            data-testid="tab-dynamic"
          >
            Dynamic / Manual <span className="ml-2 inline-flex min-w-[22px] items-center justify-center rounded-full bg-secondary px-1.5 py-0.5 text-xs text-muted-foreground">{filteredDynamicLeases.length}</span>
          </button>
        </div>

        {/* Body */}
        {loading ? (
          <div className="p-12 text-center text-muted-foreground">Loading leases…</div>
        ) : variant === 'all' ? (
          <div className="space-y-6" data-testid="all-leases-container">
            <StaticLeasesTable
              leases={filteredStaticLeases}
              routerName={routerName}
              onRegister={openRegistrationModal}
              onPushKnownCustomer={(lease, customerId, confirmation) => void handleQuickRegister(lease, { existing_customer_id: customerId, ...confirmation })}
              onReassign={openReassignmentModal}
              registeringId={registeringId}
            />
            <DynamicLeasesTable
              leases={filteredDynamicLeases}
              routerName={routerName}
              onQuickRegister={openRegistrationModal}
              onPushKnownCustomer={(lease, customerId, confirmation) => void handleQuickRegister(lease, { existing_customer_id: customerId, ...confirmation })}
              onReassign={openReassignmentModal}
              onManualRegister={handleManualAdd}
              onClientMigration={() => navigate('/super-admin/client-migrations')}
              registeringId={registeringId}
            />
          </div>
        ) : variant === 'static_commented' ? (
          <StaticLeasesTable
            leases={filteredStaticLeases}
            routerName={routerName}
            onRegister={openRegistrationModal}
            onPushKnownCustomer={(lease, customerId, confirmation) => void handleQuickRegister(lease, { existing_customer_id: customerId, ...confirmation })}
            onReassign={openReassignmentModal}
            registeringId={registeringId}
          />
        ) : (
          <DynamicLeasesTable
            leases={filteredDynamicLeases}
            routerName={routerName}
            onQuickRegister={openRegistrationModal}
            onPushKnownCustomer={(lease, customerId, confirmation) => void handleQuickRegister(lease, { existing_customer_id: customerId, ...confirmation })}
            onReassign={openReassignmentModal}
            onManualRegister={handleManualAdd}
            onClientMigration={() => navigate('/super-admin/client-migrations')}
            registeringId={registeringId}
          />
        )}

        {/* Quick-register modal for dynamic / uncommented leases */}
        {modalLease && (
          <QuickRegisterModal
            lease={modalLease}
            plans={plans}
            customers={customerLinkCandidates}
            busy={registeringId === modalLease.id}
            mode={modalMode}
            onClose={() => { setModalLease(null); setModalMode('register'); }}
            onSubmit={(payload) => handleQuickRegister(modalLease, payload)}
          />
        )}
      </div>
    </DashboardLayout>
  );
};

// -----------------------------------------------------------------------------
// Static + Comment tab
// -----------------------------------------------------------------------------
const StaticLeasesTable: React.FC<{
  leases: UnregisteredLease[];
  routerName: (id: string) => string;
  onRegister: (lease: UnregisteredLease) => void;
  onPushKnownCustomer: (lease: UnregisteredLease, customerId: string, confirmation?: { confirm_current_client_reassignment?: boolean }) => void;
  onReassign: (lease: UnregisteredLease) => void;
  registeringId: string | null;
}> = ({ leases, routerName, onRegister, onPushKnownCustomer, onReassign, registeringId }) => {
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
              <th className="px-4 py-3 text-left">MAC state</th>
              <th className="px-4 py-3 text-left">Router</th>
              <th className="px-4 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            {leases.map((lease) => (
              <tr key={lease.id} className="border-t border-border" data-testid={`static-lease-row-${lease.id}`}>
                <td className="px-4 py-3">
                  <div className="font-medium text-foreground">{lease.comment || '(no comment)'}</div>
                  <LeaseCustomerIdentity lease={lease} />
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
                <td className="px-4 py-3"><LeaseActivityBadge lease={lease} /></td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5 text-muted-foreground">
                    <RouterIcon className="w-4 h-4" />
                    <span>{routerName(lease.router_id)}</span>
                  </div>
                </td>
                <td className="px-4 py-3 text-right">
                  {!lease.is_current || lease.status !== 'bound' ? (
                    <span className="inline-flex rounded-md bg-muted px-2.5 py-1.5 text-xs font-medium text-muted-foreground">Inactive lease — view only</span>
                  ) : lease.known_customer_identity?.status === 'known_customer' ? (
                    <div className="flex flex-wrap justify-end gap-2">
                      {lease.known_customer_identity.customer?.can_push_to_router && (
                        <button
                          type="button"
                          onClick={() => onPushKnownCustomer(lease, lease.known_customer_identity!.customer!.id)}
                          disabled={registeringId === lease.id}
                          className="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                          <UserCheck className="h-4 w-4" />
                          {registeringId === lease.id ? 'Pushing…' : 'Push to current client'}
                        </button>
                      )}
                      {!lease.known_customer_identity.customer?.can_push_to_router && (
                        <button
                          type="button"
                          onClick={() => {
                            const customer = lease.known_customer_identity!.customer!;
                            if (window.confirm(`Move ${customer.full_name} (${customer.account_number}) to this current ${routerName(lease.router_id)} DHCP lease? This keeps the customer account and billing unchanged, but updates its router and current IP.`)) {
                              onPushKnownCustomer(lease, customer.id, { confirm_current_client_reassignment: true });
                            }
                          }}
                          disabled={registeringId === lease.id}
                          className="inline-flex items-center gap-1.5 rounded-md bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:opacity-50"
                        >
                          <UserCheck className="h-4 w-4" />
                          {registeringId === lease.id ? 'Reassigning…' : 'Reassign to current client'}
                        </button>
                      )}
                      <button
                        type="button"
                        onClick={() => onReassign(lease)}
                        disabled={registeringId === lease.id}
                        className="inline-flex items-center gap-1.5 rounded-md border border-amber-500 px-3 py-1.5 text-sm font-semibold text-amber-800 transition hover:bg-amber-50 disabled:opacity-50 dark:text-amber-200 dark:hover:bg-amber-950/30"
                      >
                        <AlertTriangle className="h-4 w-4" />
                        Reassign to another client
                      </button>
                    </div>
                  ) : lease.known_customer_identity ? (
                    <button type="button" onClick={() => onReassign(lease)} disabled={registeringId === lease.id} className="inline-flex items-center gap-1.5 rounded-md border border-rose-500 px-3 py-1.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50 dark:text-rose-200 dark:hover:bg-rose-950/30">
                      <AlertTriangle className="h-4 w-4" /> Set final MAC owner
                    </button>
                  ) : (
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
                  )}
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
// Dynamic leases require an explicit operator choice; they are never auto-registered.
// -----------------------------------------------------------------------------
const DynamicLeasesTable: React.FC<{
  leases: UnregisteredLease[];
  routerName: (id: string) => string;
  onQuickRegister: (lease: UnregisteredLease) => void;
  onPushKnownCustomer: (lease: UnregisteredLease, customerId: string, confirmation?: { confirm_current_client_reassignment?: boolean }) => void;
  onReassign: (lease: UnregisteredLease) => void;
  onManualRegister: (lease: UnregisteredLease) => void;
  onClientMigration: () => void;
  registeringId: string | null;
}> = ({ leases, routerName, onQuickRegister, onPushKnownCustomer, onReassign, onManualRegister, onClientMigration, registeringId }) => {
  if (leases.length === 0) {
    return (
      <EmptyState
        icon={<MapPin className="w-8 h-8 text-muted-foreground" />}
        title="No unmatched dynamic leases"
        subtitle="Dynamic MikroTik DHCP leases (or static leases without a comment) appear here for review. Use Manual Registration or Client Migration to bind one."
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
              <th className="px-4 py-3 text-left">Hostname / customer identity</th>
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
                  <LeaseCustomerIdentity lease={lease} />
                  {lease.comment && (
                    <div className="text-xs text-muted-foreground">note: {lease.comment}</div>
                  )}
                </td>
                <td className="px-4 py-3 font-mono text-xs">
                  <div>{lease.mac_address}</div>
                  <div className="text-muted-foreground">{lease.ip_address}</div>
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-col items-start gap-1.5"><span
                    className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                      lease.is_dynamic
                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                        : 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200'
                    }`}
                  >
                    {lease.is_dynamic ? 'dynamic' : 'static (no comment)'}
                  </span><LeaseActivityBadge lease={lease} /></div>
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
                  {!lease.is_current || lease.status !== 'bound' ? (
                    <span className="inline-flex rounded-md bg-muted px-2.5 py-1.5 text-xs font-medium text-muted-foreground">Inactive lease — view only</span>
                  ) : lease.known_customer_identity?.status === 'known_customer' ? (
                    <div className="flex flex-wrap justify-end gap-2">
                      {lease.known_customer_identity.customer?.can_push_to_router && (
                        <button
                          type="button"
                          onClick={() => onPushKnownCustomer(lease, lease.known_customer_identity!.customer!.id)}
                          disabled={registeringId === lease.id}
                          className="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                          <UserCheck className="h-4 w-4" />
                          {registeringId === lease.id ? 'Pushing…' : 'Push to current client'}
                        </button>
                      )}
                      {!lease.known_customer_identity.customer?.can_push_to_router && (
                        <button
                          type="button"
                          onClick={() => {
                            const customer = lease.known_customer_identity!.customer!;
                            if (window.confirm(`Move ${customer.full_name} (${customer.account_number}) to this current ${routerName(lease.router_id)} DHCP lease? This keeps the customer account and billing unchanged, but updates its router and current IP.`)) {
                              onPushKnownCustomer(lease, customer.id, { confirm_current_client_reassignment: true });
                            }
                          }}
                          disabled={registeringId === lease.id}
                          className="inline-flex items-center gap-1.5 rounded-md bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:opacity-50"
                        >
                          <UserCheck className="h-4 w-4" />
                          {registeringId === lease.id ? 'Reassigning…' : 'Reassign to current client'}
                        </button>
                      )}
                      <button
                        type="button"
                        onClick={() => onReassign(lease)}
                        disabled={registeringId === lease.id}
                        className="inline-flex items-center gap-1.5 rounded-md border border-amber-500 px-3 py-1.5 text-sm font-semibold text-amber-800 transition hover:bg-amber-50 disabled:opacity-50 dark:text-amber-200 dark:hover:bg-amber-950/30"
                      >
                        <AlertTriangle className="h-4 w-4" />
                        Reassign to another client
                      </button>
                    </div>
                  ) : lease.known_customer_identity ? (
                    <button type="button" onClick={() => onReassign(lease)} disabled={registeringId === lease.id} className="inline-flex items-center gap-1.5 rounded-md border border-rose-500 px-3 py-1.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-50 dark:text-rose-200 dark:hover:bg-rose-950/30">
                      <AlertTriangle className="h-4 w-4" /> Set final MAC owner
                    </button>
                  ) : (
                    <div className="flex flex-wrap items-center gap-2 justify-end">
                      <button
                        type="button"
                        onClick={() => onQuickRegister(lease)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-primary text-primary-foreground rounded-md hover:opacity-90 transition"
                        data-testid={`quick-register-dynamic-btn-${lease.id}`}
                      >
                        <UserPlus className="w-4 h-4" />
                        Link / register
                      </button>
                      <button
                        type="button"
                        onClick={() => onManualRegister(lease)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 border border-primary text-primary rounded-md hover:bg-primary/10 transition"
                        data-testid={`manual-register-dynamic-btn-${lease.id}`}
                      >
                        <UserPlus className="w-4 h-4" />
                        Manual registration
                      </button>
                      <button
                        type="button"
                        onClick={onClientMigration}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 border border-primary text-primary rounded-md hover:bg-primary/10 transition"
                        data-testid={`client-migration-dynamic-btn-${lease.id}`}
                      >
                        Client migration
                      </button>
                    </div>
                  )}
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
  customers: CustomerLinkCandidate[];
  busy: boolean;
  mode: 'register' | 'reassign';
  onClose: () => void;
  onSubmit: (payload: { existing_customer_id?: string; full_name?: string; service_plan_id?: string; monthly_fee?: number; confirm_mac_reassignment?: boolean; confirm_duplicate_mac_resolution?: boolean }) => void;
}> = ({ lease, plans, customers, busy, mode, onClose, onSubmit }) => {
  const [fullName, setFullName] = useState<string>(
    lease.comment || lease.hostname || `Client ${lease.mac_address.slice(-5)}`
  );
  const [planId, setPlanId] = useState<string>(lease.suggested_plan?.id ?? plans[0]?.id ?? '');
  const [existingCustomerId, setExistingCustomerId] = useState<string>('');
  const [customerSearch, setCustomerSearch] = useState<string>('');
  const [reassignmentConfirmed, setReassignmentConfirmed] = useState<boolean>(false);
  const selectedCustomer = customers.find((customer) => customer.id === existingCustomerId);
  const linkingExistingCustomer = Boolean(selectedCustomer);
  const chosenPlan = plans.find((p) => p.id === planId);
  const currentCustomer = lease.known_customer_identity?.customer;
  const isDuplicateResolution = mode === 'reassign' && lease.known_customer_identity?.status === 'ambiguous';
  const isReassignment = mode === 'reassign' && (Boolean(currentCustomer) || isDuplicateResolution);
  const normalizedCustomerSearch = customerSearch.trim().toLowerCase();
  const visibleCustomers = normalizedCustomerSearch
    ? customers.filter((customer) => [customer.full_name, customer.account_number, customer.address]
        .some((value) => String(value || '').toLowerCase().includes(normalizedCustomerSearch)))
    : customers;
  const duplicateOwnerIds = new Set(lease.known_customer_identity?.customers?.map((owner) => owner.id) ?? []);
  const duplicateOwnerCandidates = visibleCustomers.filter((customer) => duplicateOwnerIds.has(customer.id));
  const otherCustomerCandidates = visibleCustomers.filter((customer) => !duplicateOwnerIds.has(customer.id));

  const customerOption = (customer: CustomerLinkCandidate, disabled = false): React.JSX.Element => (
    <option key={customer.id} value={customer.id} disabled={disabled || !customer.service_plan_id || customer.status === 'pending'}>
      {customer.full_name} · {customer.account_number}{customer.service_plan ? ` · ${customer.service_plan.name}` : ' · no plan set'}
      {customer.status === 'pending' ? ' · pending approval' : ''}
      {disabled ? ' · current owner' : ''}
    </option>
  );

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
            <h3 className="text-lg font-semibold text-foreground">{isReassignment ? 'Reassign device to another client' : 'Register client'}</h3>
            <p className="text-xs text-muted-foreground mt-1">
              {isReassignment
                ? isDuplicateResolution
                  ? 'Choose the one real user of this MAC. Other customer accounts keep their billing history; only their duplicate MAC and stale IP links are cleared.'
                  : 'Choose Client B and confirm the transfer. Client A keeps all billing history; only the reused device MAC and stale IP are removed from Client A.'
                : 'Pick a plan — MikroTik will be updated with comment, made static, and rate-limited to the plan.'}
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

        {isReassignment && (
          <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
            {isDuplicateResolution ? (
              <><p className="font-semibold">Duplicate MAC currently appears on:</p><ul className="mt-1 list-disc pl-5">{lease.known_customer_identity?.customers?.map((owner) => <li key={owner.id}>{owner.full_name} · {owner.account_number}</li>)}</ul></>
            ) : currentCustomer ? (
              <><p className="font-semibold">Current device owner: {currentCustomer.full_name} · {currentCustomer.account_number}</p><p className="mt-1">Use this only after Client A has disconnected and this same ONU/router is now installed for Client B.</p></>
            ) : null}
            <label className="mt-3 flex cursor-pointer items-start gap-2 font-medium">
              <input type="checkbox" checked={reassignmentConfirmed} onChange={(event) => setReassignmentConfirmed(event.target.checked)} className="mt-0.5" />
              <span>{isDuplicateResolution ? 'I verified the real user. Make the selected customer the only owner of this MAC.' : 'I confirm Client A no longer uses this device. Move this MAC and current DHCP lease to Client B.'}</span>
            </label>
          </div>
        )}

        <div>
          <label className="block text-sm font-medium mb-1.5">{isDuplicateResolution ? 'Final real MAC owner *' : isReassignment ? 'New customer (Client B) *' : 'Link to an existing customer (optional)'}</label>
          <input
            type="search"
            value={customerSearch}
            onChange={(event) => setCustomerSearch(event.target.value)}
            placeholder="Search every customer by name, account, or address"
            className="mb-2 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
          />
          <select
            value={existingCustomerId}
            onChange={(event) => {
              const selectedId = event.target.value;
              const customer = customers.find((candidate) => candidate.id === selectedId);
              setExistingCustomerId(selectedId);
              setReassignmentConfirmed(false);
              if (customer) {
                setFullName(customer.full_name);
                setPlanId(customer.service_plan_id ?? '');
              }
            }}
            className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
            data-testid="quick-register-existing-customer"
          >
            <option value="">{isDuplicateResolution ? 'Select the real user' : isReassignment ? 'Select Client B' : 'Create a new customer from this lease'}</option>
            {isDuplicateResolution ? (
              <>
                <optgroup label="Currently using this duplicate MAC">
                  {duplicateOwnerCandidates.map((customer) => customerOption(customer))}
                </optgroup>
                <optgroup label="All other registered customers">
                  {otherCustomerCandidates.map((customer) => customerOption(customer))}
                </optgroup>
              </>
            ) : isReassignment && currentCustomer ? (
              <>
                <optgroup label="Current owner (cannot be Client B)">
                  {customers.filter((customer) => customer.id === currentCustomer.id).map((customer) => customerOption(customer, true))}
                </optgroup>
                <optgroup label="All other registered customers">
                  {customers.filter((customer) => customer.id !== currentCustomer.id).map((customer) => customerOption(customer))}
                </optgroup>
              </>
            ) : visibleCustomers.map((customer) => customerOption(customer))}
          </select>
          <p className="text-xs text-muted-foreground mt-1">
            {isReassignment
              ? `Showing ${visibleCustomers.length} of ${customers.length} customer profiles. Pending customers and customers without an assigned plan remain visible but cannot receive the device yet.`
              : `Showing ${visibleCustomers.length} of ${customers.length} customer profiles. Select a registered customer for an ONU/router replacement; billing information remains unchanged.`}
          </p>
          {selectedCustomer && (
            <div className="mt-2 rounded-md border border-primary/25 bg-primary/5 px-3 py-2 text-xs text-muted-foreground">
              <div className="font-medium text-foreground">{selectedCustomer.full_name} · {selectedCustomer.account_number}</div>
              <div>{selectedCustomer.address || 'Address to be updated'}</div>
            </div>
          )}
        </div>

        <div>
          <label className="block text-sm font-medium mb-1.5">Full name</label>
          <input
            type="text"
            value={fullName}
            onChange={(e) => setFullName(e.target.value)}
            disabled={linkingExistingCustomer}
            className="w-full px-3 py-2 border border-input rounded-md bg-background text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
            data-testid="quick-register-full-name"
          />
        </div>

        <div>
          <label className="block text-sm font-medium mb-1.5">Service plan</label>
          <select
            value={planId}
            onChange={(e) => setPlanId(e.target.value)}
            disabled={linkingExistingCustomer}
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
            disabled={busy || (isReassignment ? (!linkingExistingCustomer || !reassignmentConfirmed) : (!linkingExistingCustomer && !fullName.trim()))}
            onClick={() =>
              onSubmit({
                existing_customer_id: existingCustomerId || undefined,
                full_name: linkingExistingCustomer ? undefined : fullName.trim(),
                service_plan_id: linkingExistingCustomer ? undefined : (planId || undefined),
                monthly_fee: linkingExistingCustomer ? undefined : chosenPlan?.price,
                confirm_mac_reassignment: isReassignment ? reassignmentConfirmed : undefined,
                confirm_duplicate_mac_resolution: isDuplicateResolution ? reassignmentConfirmed : undefined,
              })
            }
            className="inline-flex items-center gap-1.5 px-4 py-2 text-sm bg-primary text-primary-foreground rounded-md hover:opacity-90 disabled:opacity-50"
            data-testid="quick-register-submit"
          >
            <UserPlus className="w-4 h-4" />
            {busy ? 'Saving…' : isReassignment ? 'Reassign + Push to MikroTik' : linkingExistingCustomer ? 'Link + Push to MikroTik' : 'Register + Push to MikroTik'}
          </button>
        </div>
      </div>
    </div>
  );
};

export default UnregisteredLeasesPage;
