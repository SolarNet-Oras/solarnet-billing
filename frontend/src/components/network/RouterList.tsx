import { useState } from 'react';
import { type Router, routerService } from '@/services/routerService';
import { Circle, Edit, FileCode, Network, RefreshCw, Server, ShieldAlert, ShieldCheck, Terminal, TestTube, Trash2, Users, Wifi, WifiOff } from 'lucide-react';
import { SetupScriptModal } from './SetupScriptModal';
import { RouterConsoleModal } from './RouterConsoleModal';
import { RouterDnsBrandingModal } from './RouterDnsBrandingModal';
import { RouterProvisioningModal } from './RouterProvisioningModal';

interface RouterListProps {
  routers: Router[];
  onEdit: (router: Router) => void;
  onDelete: (id: string) => void;
  onTestConnection: (id: string) => void;
  onSync: (id: string) => void;
}

export function RouterList({ routers, onEdit, onDelete, onTestConnection, onSync }: RouterListProps) {
  const [testingId, setTestingId] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<{ id: string; success: boolean; message: string } | null>(null);
  const [syncingId, setSyncingId] = useState<string | null>(null);
  const [dhcpSyncingId, setDhcpSyncingId] = useState<string | null>(null);
  const [scriptModalOpen, setScriptModalOpen] = useState(false);
  const [selectedRouter, setSelectedRouter] = useState<Router | null>(null);
  const [consoleRouter, setConsoleRouter] = useState<Router | null>(null);
  const [dnsBrandingRouter, setDnsBrandingRouter] = useState<Router | null>(null);
  const [provisioningRouter, setProvisioningRouter] = useState<Router | null>(null);
  const [billingActionId, setBillingActionId] = useState<string | null>(null);
  const [billingResult, setBillingResult] = useState<{ id: string; success: boolean; message: string } | null>(null);
  const [dnsScanAllBusy, setDnsScanAllBusy] = useState(false);
  const [dnsScanAllMessage, setDnsScanAllMessage] = useState<string | null>(null);

  const handleTest = async (id: string) => {
    setTestingId(id);
    setTestResult(null);
    try {
      const result = await routerService.testConnection(id);
      setTestResult({ id, success: result.success, message: result.message });
      onTestConnection(id);
    } catch (error: any) {
      setTestResult({ id, success: false, message: error.response?.data?.message || error.message || 'Test failed' });
    } finally {
      setTestingId(null);
      setTimeout(() => setTestResult(null), 5_000);
    }
  };

  const handleSync = async (id: string) => {
    setSyncingId(id);
    try {
      await routerService.sync(id);
      onSync(id);
    } catch (error: any) {
      console.error('Sync failed:', error);
      const details = error?.response?.data?.errors || error?.response?.data?.dhcp_sync?.errors || [];
      const explanation = details.length
        ? details.map((detail: string) => `• ${detail}`).join('\n')
        : (error?.response?.data?.message || error?.message || 'No error detail was returned.');
      alert(
        `Router sync needs attention.\n\n${explanation}\n\n` +
        'The connection check passed only for basic RouterOS information. The failed item above identifies whether DHCP lease reading, static-lease enforcement, or queue synchronization needs attention.'
      );
    } finally {
      setSyncingId(null);
    }
  };

  const handleDhcpSync = async (id: string) => {
    setDhcpSyncingId(id);
    try {
      const response = await routerService.syncDhcp(id, true);
      const result = response.data;
      alert(`DHCP Sync Complete!\nFetched: ${result.leases_fetched}\nMatched: ${result.customers_matched}\nIPs Updated: ${result.ips_updated}\nMade static: ${result.static_leases_converted || 0}\nOwnership comments applied: ${result.ownership_comments_applied || 0}\nRegistered static leases verified: ${result.registered_static_leases_verified || 0}`);
      onSync(id);
    } catch (error: any) {
      console.error('DHCP sync failed:', error);
      const details = error?.response?.data?.data?.errors || [];
      const explanation = details.length
        ? details.map((detail: string) => `• ${detail}`).join('\n')
        : (error?.response?.data?.message || error?.message || 'No error detail was returned.');
      alert(
        `DHCP sync needs attention.\n\n${explanation}\n\n` +
        'Connection Test only checks RouterOS system information. DHCP sync also needs access to DHCP leases; exact registered matches additionally need permission to make a lease static and update its queue.'
      );
    } finally {
      setDhcpSyncingId(null);
    }
  };

  const handleInstallBillingAccess = async (router: Router) => {
    if (!confirm(`Install or update SolarNet payment-only firewall rules on ${router.name}?\n\nSuspended customers will be allowed DNS and the configured payment portal, while other forwarded internet traffic is blocked. Existing SolarNet billing rules will be replaced; unrelated firewall rules are not changed.`)) return;
    setBillingActionId(router.id);
    setBillingResult(null);
    try {
      const result = await routerService.installBillingAccess(router.id);
      setBillingResult({ id: router.id, success: result.success, message: result.message });
    } catch (error: any) {
      const timedOut = error?.code === 'ECONNABORTED' || /timeout/i.test(String(error?.message || ''));
      setBillingResult({ id: router.id, success: false, message: timedOut ? 'Billing-rule installation exceeded the three-minute browser limit. Do not click Install again; wait one minute, then use Verify billing access to check the idempotent update.' : error.response?.data?.message || error.message || 'Failed to install billing access rules.' });
    } finally {
      setBillingActionId(null);
    }
  };

  const handleVerifyBillingAccess = async (router: Router) => {
    setBillingActionId(router.id);
    setBillingResult(null);
    try {
      const result = await routerService.billingAccessStatus(router.id);
      setBillingResult({ id: router.id, success: result.installed, message: result.installed ? `Billing access is installed and verified (${result.rule_count} rules).` : `Billing access is incomplete (${result.rule_count} of 4 rules found).` });
    } catch (error: any) {
      setBillingResult({ id: router.id, success: false, message: error.response?.data?.message || error.message || 'Failed to verify billing access rules.' });
    } finally {
      setBillingActionId(null);
    }
  };

  const handleAuditBillingAccess = async (router: Router) => {
    setBillingActionId(router.id);
    setBillingResult(null);
    try {
      const result = await routerService.billingAccessAudit(router.id);
      const interfaces = result.audit.customer_interfaces.map((item) => `${item.interface}${item.gateway ? ` (${item.gateway})` : ''}`).join(', ');
      setBillingResult({ id: router.id, success: true, message: `Safety audit: ${result.audit.dhcp_server_count} DHCP server(s) on ${interfaces || 'no detected customer interfaces'}. ${result.audit.safety_note}` });
    } catch (error: any) {
      setBillingResult({ id: router.id, success: false, message: error.response?.data?.message || error.message || 'Failed to audit router configuration.' });
    } finally {
      setBillingActionId(null);
    }
  };

  const handleRemoveBillingAccess = async (router: Router) => {
    if (!confirm(`Remove only SolarNet billing firewall rules from ${router.name}?\n\nSuspended customers will no longer have payment-only access restrictions from this router.`)) return;
    setBillingActionId(router.id);
    setBillingResult(null);
    try {
      const result = await routerService.removeBillingAccess(router.id);
      setBillingResult({ id: router.id, success: result.success, message: result.message });
    } catch (error: any) {
      setBillingResult({ id: router.id, success: false, message: error.response?.data?.message || error.message || 'Failed to remove billing access rules.' });
    } finally {
      setBillingActionId(null);
    }
  };

  const handleDnsScanAll = async () => {
    setDnsScanAllBusy(true);
    setDnsScanAllMessage(null);
    try {
      const results = await routerService.dnsBrandingScanAll();
      const successful = results.filter((result) => result.success).length;
      const failed = results.length - successful;
      setDnsScanAllMessage(`Read-only DNS scan finished: ${successful} compatible API scan${successful === 1 ? '' : 's'}${failed ? `, ${failed} unavailable` : ''}. No router configuration was changed.`);
    } catch (error: any) {
      setDnsScanAllMessage(error.response?.data?.message || error.message || 'Could not scan router DNS configuration. No router configuration was changed.');
    } finally {
      setDnsScanAllBusy(false);
    }
  };

  const statusPresentation = (status: Router['connection_status']) => status === 'online'
    ? { label: 'Online', icon: <Wifi className="h-3.5 w-3.5" />, className: 'border-emerald-400/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200', led: 'bg-emerald-400 shadow-[0_0_12px_rgba(52,211,153,0.9)]' }
    : status === 'offline'
      ? { label: 'Offline', icon: <WifiOff className="h-3.5 w-3.5" />, className: 'border-red-400/30 bg-red-500/10 text-red-700 dark:text-red-200', led: 'bg-red-400 shadow-[0_0_12px_rgba(248,113,113,0.9)]' }
      : { label: 'Unknown', icon: <Circle className="h-3.5 w-3.5" />, className: 'border-slate-400/30 bg-slate-500/10 text-slate-600 dark:text-slate-300', led: 'bg-slate-400' };

  if (routers.length === 0) return <div className="rounded-xl border border-dashed border-border p-10 text-center"><Server className="mx-auto mb-3 h-10 w-10 text-muted-foreground" /><h3 className="text-lg font-semibold text-foreground">No routers configured</h3><p className="mt-1 text-sm text-muted-foreground">Add a MikroTik router to start managing the network.</p></div>;

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 sm:px-5">
        <div><h3 className="text-sm font-semibold text-foreground">Router racks</h3><p className="text-xs text-muted-foreground">All existing router actions are available from each rack.</p></div>
        <button type="button" onClick={() => void handleDnsScanAll()} disabled={dnsScanAllBusy} className="inline-flex items-center gap-2 rounded-md border border-cyan-500/40 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-50 disabled:opacity-50 dark:text-cyan-200 dark:hover:bg-cyan-900/20" title="Run a read-only internal DNS compatibility scan on each router">
          {dnsScanAllBusy ? <div className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-cyan-600 border-t-transparent" /> : <Network className="h-3.5 w-3.5" />} Scan all routers DNS
        </button>
      </div>
      {dnsScanAllMessage && <div className="border-b border-border bg-cyan-500/5 px-4 py-2 text-xs text-cyan-900 dark:text-cyan-100">{dnsScanAllMessage}</div>}

      <div className="space-y-3 bg-muted/25 p-3 sm:p-4">
        {routers.map((router) => {
          const status = statusPresentation(router.connection_status);
          const busy = billingActionId === router.id;
          return <article key={router.id} className="relative overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-cyan-400/60 to-transparent" />
            <div className="grid gap-4 p-3 sm:p-4 xl:grid-cols-[auto_minmax(0,1fr)_minmax(25rem,0.9fr)] xl:items-center">
              <div className="flex items-center gap-3 xl:items-stretch">
                <div className="grid h-20 w-14 shrink-0 grid-rows-[auto_repeat(4,minmax(0,1fr))] rounded-lg border border-slate-700 bg-slate-950 p-2 shadow-inner">
                  <span className="flex items-center gap-1"><i className={`h-2 w-2 rounded-full ${status.led}`} /><i className="h-1 w-1 rounded-full bg-cyan-300" /></span>
                  <span className="rounded-sm bg-slate-700/80" /><span className="rounded-sm bg-slate-700/80" /><span className="rounded-sm bg-slate-700/80" /><span className="rounded-sm bg-slate-700/80" />
                </div>
                <div className="min-w-0 xl:hidden"><p className="truncate font-semibold text-foreground">{router.name}</p><p className="truncate text-xs text-muted-foreground">{router.host}:{router.port}</p></div>
              </div>

              <div className="min-w-0">
                <div className="hidden items-start justify-between gap-3 xl:flex"><div><p className="truncate text-base font-semibold text-foreground">{router.name}</p><p className="mt-0.5 truncate font-mono text-xs text-muted-foreground">{router.host}:{router.port}</p></div></div>
                <div className="mt-2 flex flex-wrap gap-2 text-xs"><span className={`inline-flex items-center gap-1 rounded-full border px-2 py-1 font-semibold ${status.className}`}>{status.icon}{status.label}</span>{router.location && <span className="rounded-full bg-muted px-2 py-1 text-muted-foreground">{router.location}</span>}{router.routeros_version && <span className="rounded-full bg-muted px-2 py-1 text-muted-foreground">RouterOS {router.routeros_version}</span>}{router.dhcp_pool_name && <span className="rounded-full bg-muted px-2 py-1 text-muted-foreground">Pool: {router.dhcp_pool_name}</span>}</div>
              </div>

              <div className="grid gap-2 text-xs sm:grid-cols-3 xl:grid-cols-1">
                <div className="flex flex-wrap items-center gap-1.5"><span className="mr-1 font-medium text-muted-foreground">Router tools</span><button onClick={() => setProvisioningRouter(router)} className="rounded-md border border-cyan-500/35 px-2 py-1.5 font-semibold text-cyan-700 hover:bg-cyan-50 dark:text-cyan-300 dark:hover:bg-cyan-900/20" title="Set up a new, clean router using IPoE" data-testid="router-provision-btn">Set up</button><button onClick={() => setDnsBrandingRouter(router)} className="rounded-md border border-teal-500/35 px-2 py-1.5 font-semibold text-teal-700 hover:bg-teal-50 dark:text-teal-300 dark:hover:bg-teal-900/20" title="DNS Management: internal SolarNet DNS branding" data-testid="router-dns-management-btn">DNS</button><button onClick={() => { setSelectedRouter(router); setScriptModalOpen(true); }} className="rounded-md border border-violet-500/35 p-1.5 text-violet-700 hover:bg-violet-50 dark:text-violet-300 dark:hover:bg-violet-900/20" title="Generate setup script" aria-label="Generate setup script"><FileCode className="h-3.5 w-3.5" /></button><button onClick={() => setConsoleRouter(router)} className="rounded-md border border-slate-400/35 p-1.5 text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800" title="Open MikroTik console" aria-label="Open MikroTik console" data-testid="router-console-btn"><Terminal className="h-3.5 w-3.5" /></button></div>
                <div className="flex flex-wrap items-center gap-1.5"><span className="mr-1 font-medium text-muted-foreground">Billing access</span><button onClick={() => void handleInstallBillingAccess(router)} disabled={busy} className="rounded-md border border-emerald-500/35 p-1.5 text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-300 dark:hover:bg-emerald-900/20" title="Install payment-only billing access" aria-label="Install payment-only billing access" data-testid="router-install-billing-access-btn">{busy ? <div className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-emerald-600 border-t-transparent" /> : <ShieldCheck className="h-3.5 w-3.5" />}</button><button onClick={() => void handleAuditBillingAccess(router)} disabled={busy} className="rounded-md border border-cyan-500/35 p-1.5 text-cyan-700 hover:bg-cyan-50 disabled:opacity-50 dark:text-cyan-300 dark:hover:bg-cyan-900/20" title="Read network safety audit" aria-label="Read network safety audit" data-testid="router-billing-audit-btn"><Network className="h-3.5 w-3.5" /></button><button onClick={() => void handleVerifyBillingAccess(router)} disabled={busy} className="rounded-md border border-amber-500/35 p-1.5 text-amber-700 hover:bg-amber-50 disabled:opacity-50 dark:text-amber-300 dark:hover:bg-amber-900/20" title="Verify billing access rules" aria-label="Verify billing access rules" data-testid="router-verify-billing-access-btn"><ShieldAlert className="h-3.5 w-3.5" /></button><button onClick={() => void handleRemoveBillingAccess(router)} disabled={busy} className="rounded-md border border-red-500/35 px-2 py-1.5 text-red-700 hover:bg-red-50 disabled:opacity-50 dark:text-red-300 dark:hover:bg-red-900/20" title="Remove SolarNet billing rules" data-testid="router-remove-billing-access-btn">Remove</button></div>
                <div className="flex flex-wrap items-center gap-1.5"><span className="mr-1 font-medium text-muted-foreground">Maintenance</span><button onClick={() => void handleDhcpSync(router.id)} disabled={dhcpSyncingId === router.id} className="rounded-md border border-orange-500/35 p-1.5 text-orange-700 hover:bg-orange-50 disabled:opacity-50 dark:text-orange-300 dark:hover:bg-orange-900/20" title="Sync DHCP leases" aria-label="Sync DHCP">{dhcpSyncingId === router.id ? <div className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-orange-600 border-t-transparent" /> : <Users className="h-3.5 w-3.5" />}</button><button onClick={() => void handleTest(router.id)} disabled={testingId === router.id} className="rounded-md border border-blue-500/35 p-1.5 text-blue-700 hover:bg-blue-50 disabled:opacity-50 dark:text-blue-300 dark:hover:bg-blue-900/20" title="Test connection" aria-label="Test connection" data-testid="router-test-btn">{testingId === router.id ? <div className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" /> : <TestTube className="h-3.5 w-3.5" />}</button><button onClick={() => void handleSync(router.id)} disabled={syncingId === router.id} className="rounded-md border border-emerald-500/35 p-1.5 text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-300 dark:hover:bg-emerald-900/20" title="Sync router" aria-label="Sync router" data-testid="router-sync-btn">{syncingId === router.id ? <div className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-emerald-600 border-t-transparent" /> : <RefreshCw className="h-3.5 w-3.5" />}</button><button onClick={() => onEdit(router)} className="rounded-md border border-slate-400/35 p-1.5 text-foreground hover:bg-secondary" title="Edit router" aria-label="Edit router" data-testid="router-edit-btn"><Edit className="h-3.5 w-3.5" /></button><button onClick={() => onDelete(router.id)} className="rounded-md border border-red-500/35 p-1.5 text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/20" title="Delete router" aria-label="Delete router" data-testid="router-delete-btn"><Trash2 className="h-3.5 w-3.5" /></button></div>
              </div>
            </div>
            {billingResult?.id === router.id && <div className={`border-t px-4 py-2 text-xs ${billingResult.success ? 'border-emerald-500/20 bg-emerald-500/5 text-emerald-800 dark:text-emerald-200' : 'border-red-500/20 bg-red-500/5 text-red-800 dark:text-red-200'}`}>{billingResult.message}</div>}
          </article>;
        })}
      </div>

      {testResult && <div className={`m-3 rounded-lg border p-3 text-sm ${testResult.success ? 'border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-200' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-200'}`}>{testResult.message}</div>}

      {selectedRouter && <SetupScriptModal isOpen={scriptModalOpen} onClose={() => setScriptModalOpen(false)} routerId={selectedRouter.id} routerName={selectedRouter.name} />}
      {consoleRouter && <RouterConsoleModal isOpen={true} onClose={() => setConsoleRouter(null)} routerId={consoleRouter.id} routerName={consoleRouter.name} />}
      {provisioningRouter && <RouterProvisioningModal isOpen={true} router={provisioningRouter} onClose={() => setProvisioningRouter(null)} />}
      {dnsBrandingRouter && <RouterDnsBrandingModal isOpen={true} router={dnsBrandingRouter} onClose={() => setDnsBrandingRouter(null)} />}
    </div>
  );
}
