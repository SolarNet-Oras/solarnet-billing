import { Fragment, useState } from 'react';
import { type Router, routerService } from '@/services/routerService';
import { Wifi, WifiOff, Circle, TestTube, RefreshCw, Edit, Trash2, FileCode, Users, ShieldCheck, ShieldAlert, Terminal, Network } from 'lucide-react';
import { SetupScriptModal } from './SetupScriptModal';
import { RouterConsoleModal } from './RouterConsoleModal';
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
  const [provisioningRouter, setProvisioningRouter] = useState<Router | null>(null);
  const [billingActionId, setBillingActionId] = useState<string | null>(null);
  const [billingResult, setBillingResult] = useState<{ id: string; success: boolean; message: string } | null>(null);

  const handleTest = async (id: string) => {
    setTestingId(id);
    setTestResult(null);
    
    try {
      const result = await routerService.testConnection(id);
      setTestResult({ id, success: result.success, message: result.message });
      onTestConnection(id);
    } catch (error: any) {
      setTestResult({ 
        id, 
        success: false, 
        message: error.response?.data?.message || error.message || 'Test failed' 
      });
    } finally {
      setTestingId(null);
      setTimeout(() => setTestResult(null), 5000);
    }
  };

  const handleSync = async (id: string) => {
    setSyncingId(id);
    try {
      await routerService.sync(id);
      onSync(id);
    } catch (error) {
      console.error('Sync failed:', error);
    } finally {
      setSyncingId(null);
    }
  };

  const handleGenerateScript = (router: Router) => {
    setSelectedRouter(router);
    setScriptModalOpen(true);
  };

  const handleDhcpSync = async (id: string) => {
    setDhcpSyncingId(id);
    try {
      const result = await routerService.syncDhcp(id, true);
      alert(`DHCP Sync Complete!\nFetched: ${result.leases_fetched}\nCustomers Created: ${result.customers_created}\nMatched: ${result.customers_matched}\nIPs Updated: ${result.ips_updated}`);
      onSync(id);
    } catch (error) {
      console.error('DHCP sync failed:', error);
      alert('DHCP sync failed');
    } finally {
      setDhcpSyncingId(null);
    }
  };

  const handleInstallBillingAccess = async (router: Router) => {
    if (!confirm(`Install or update Solarnet payment-only firewall rules on ${router.name}?\n\nSuspended customers will be allowed DNS and the configured payment portal, while other forwarded internet traffic is blocked. Existing Solarnet billing rules will be replaced; unrelated firewall rules are not changed.`)) return;

    setBillingActionId(router.id);
    setBillingResult(null);
    try {
      const result = await routerService.installBillingAccess(router.id);
      setBillingResult({ id: router.id, success: result.success, message: result.message });
    } catch (error: any) {
      const timedOut = error?.code === 'ECONNABORTED' || /timeout/i.test(String(error?.message || ''));
      setBillingResult({
        id: router.id,
        success: false,
        message: timedOut
          ? 'Billing-rule installation exceeded the three-minute browser limit. Do not click Install again; wait one minute, then use Verify billing access to check whether the router completed the idempotent update.'
          : error.response?.data?.message || error.message || 'Failed to install billing access rules.',
      });
    } finally {
      setBillingActionId(null);
    }
  };

  const handleVerifyBillingAccess = async (router: Router) => {
    setBillingActionId(router.id);
    setBillingResult(null);
    try {
      const result = await routerService.billingAccessStatus(router.id);
      setBillingResult({
        id: router.id,
        success: result.installed,
        message: result.installed
          ? `Billing access is installed and verified (${result.rule_count} rules).`
          : `Billing access is incomplete (${result.rule_count} of 4 rules found).`,
      });
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
      const interfaces = result.audit.customer_interfaces
        .map((item) => `${item.interface}${item.gateway ? ` (${item.gateway})` : ''}`)
        .join(', ');
      setBillingResult({
        id: router.id,
        success: true,
        message: `Safety audit: ${result.audit.dhcp_server_count} DHCP server(s) on ${interfaces || 'no detected customer interfaces'}. ${result.audit.safety_note}`,
      });
    } catch (error: any) {
      setBillingResult({ id: router.id, success: false, message: error.response?.data?.message || error.message || 'Failed to audit router configuration.' });
    } finally {
      setBillingActionId(null);
    }
  };

  const handleRemoveBillingAccess = async (router: Router) => {
    if (!confirm(`Remove only Solarnet billing firewall rules from ${router.name}?\n\nSuspended customers will no longer have payment-only access restrictions from this router.`)) return;

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

  const getStatusIcon = (status: Router['connection_status']) => {
    switch (status) {
      case 'online':
        return <Wifi className="h-5 w-5 text-green-600" />;
      case 'offline':
        return <WifiOff className="h-5 w-5 text-red-600" />;
      default:
        return <Circle className="h-5 w-5 text-gray-400" />;
    }
  };

  const getStatusBadge = (status: Router['connection_status']) => {
    const colors = {
      online: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
      offline: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
      unknown: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
    };
    return (
      <span className={`px-2 py-1 rounded text-xs font-medium ${colors[status]}`}>
        {status.charAt(0).toUpperCase() + status.slice(1)}
      </span>
    );
  };

  if (routers.length === 0) {
    return (
      <div className="bg-card border border-border rounded-lg p-12 text-center">
        <Wifi className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
        <h3 className="text-lg font-semibold text-foreground mb-2">No routers configured</h3>
        <p className="text-muted-foreground">
          Add your first MikroTik router to start managing your network.
        </p>
      </div>
    );
  }

  return (
    <div className="bg-card border border-border rounded-lg overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full">
          <thead className="bg-muted">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Status
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Name
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Host:Port
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Location
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Version
              </th>
              <th className="px-6 py-3 text-right text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Actions
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {routers.map((router) => (
              <Fragment key={router.id}>
              <tr className="hover:bg-muted/50 transition-colors">
                <td className="px-6 py-4 whitespace-nowrap">
                  <div className="flex items-center space-x-2">
                    {getStatusIcon(router.connection_status)}
                    {getStatusBadge(router.connection_status)}
                  </div>
                </td>
                <td className="px-6 py-4">
                  <div className="text-sm font-medium text-foreground">{router.name}</div>
                  {router.dhcp_pool_name && (
                    <div className="text-xs text-muted-foreground">Pool: {router.dhcp_pool_name}</div>
                  )}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <div className="text-sm text-foreground">{router.host}:{router.port}</div>
                </td>
                <td className="px-6 py-4">
                  <div className="text-sm text-muted-foreground">{router.location || '-'}</div>
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <div className="text-sm text-muted-foreground">{router.routeros_version || '-'}</div>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <div className="flex items-center justify-end space-x-2">
                    <button
                      onClick={() => setProvisioningRouter(router)}
                      className="rounded px-2 py-1 text-xs font-semibold text-cyan-700 hover:bg-cyan-50 dark:text-cyan-300 dark:hover:bg-cyan-900/20"
                      title="Set up a new, clean router using IPoE"
                      aria-label="Set Up MikroTik"
                      data-testid="router-provision-btn"
                    >
                      Set Up
                    </button>
                    <button
                      onClick={() => handleGenerateScript(router)}
                      className="p-2 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded transition-colors"
                      title="Generate Setup Script"
                      aria-label="Generate Setup Script"
                    >
                      <FileCode className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => setConsoleRouter(router)}
                      className="p-2 text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800 rounded transition-colors"
                      title="Open MikroTik console"
                      aria-label="Open MikroTik console"
                      data-testid="router-console-btn"
                    >
                      <Terminal className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => handleInstallBillingAccess(router)}
                      disabled={billingActionId === router.id}
                      className="p-2 text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded transition-colors disabled:opacity-50"
                      title="Install payment-only billing access"
                      aria-label="Install payment-only billing access"
                      data-testid="router-install-billing-access-btn"
                    >
                      {billingActionId === router.id ? (
                        <div className="animate-spin h-4 w-4 border-2 border-emerald-600 border-t-transparent rounded-full" />
                      ) : (
                        <ShieldCheck className="h-4 w-4" />
                      )}
                    </button>
                    <button
                      onClick={() => handleAuditBillingAccess(router)}
                      disabled={billingActionId === router.id}
                      className="p-2 text-cyan-600 hover:bg-cyan-50 dark:hover:bg-cyan-900/20 rounded transition-colors disabled:opacity-50"
                      title="Read network safety audit"
                      aria-label="Read network safety audit"
                      data-testid="router-billing-audit-btn"
                    >
                      <Network className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => handleVerifyBillingAccess(router)}
                      disabled={billingActionId === router.id}
                      className="p-2 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded transition-colors disabled:opacity-50"
                      title="Verify billing access rules"
                      aria-label="Verify billing access rules"
                      data-testid="router-verify-billing-access-btn"
                    >
                      <ShieldAlert className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => handleRemoveBillingAccess(router)}
                      disabled={billingActionId === router.id}
                      className="px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition-colors disabled:opacity-50"
                      title="Remove Solarnet billing rules"
                      aria-label="Remove Solarnet billing rules"
                      data-testid="router-remove-billing-access-btn"
                    >
                      Remove billing
                    </button>
                    <button
                      onClick={() => handleDhcpSync(router.id)}
                      disabled={dhcpSyncingId === router.id}
                      className="p-2 text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-900/20 rounded transition-colors disabled:opacity-50"
                      title="Sync DHCP Leases"
                      aria-label="Sync DHCP"
                    >
                      {dhcpSyncingId === router.id ? (
                        <div className="animate-spin h-4 w-4 border-2 border-orange-600 border-t-transparent rounded-full" />
                      ) : (
                        <Users className="h-4 w-4" />
                      )}
                    </button>
                    <button
                      onClick={() => handleTest(router.id)}
                      disabled={testingId === router.id}
                      data-testid="router-test-btn"
                      className="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded transition-colors disabled:opacity-50"
                      title="Test Connection"
                      aria-label="Test Connection"
                    >
                      {testingId === router.id ? (
                        <div className="animate-spin h-4 w-4 border-2 border-blue-600 border-t-transparent rounded-full" />
                      ) : (
                        <TestTube className="h-4 w-4" />
                      )}
                    </button>
                    <button
                      onClick={() => handleSync(router.id)}
                      disabled={syncingId === router.id}
                      data-testid="router-sync-btn"
                      className="p-2 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 rounded transition-colors disabled:opacity-50"
                      title="Sync Now"
                      aria-label="Sync Now"
                    >
                      {syncingId === router.id ? (
                        <div className="animate-spin h-4 w-4 border-2 border-green-600 border-t-transparent rounded-full" />
                      ) : (
                        <RefreshCw className="h-4 w-4" />
                      )}
                    </button>
                    <button
                      onClick={() => onEdit(router)}
                      data-testid="router-edit-btn"
                      className="p-2 text-foreground hover:bg-secondary rounded transition-colors"
                      title="Edit Router"
                      aria-label="Edit Router"
                    >
                      <Edit className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => onDelete(router.id)}
                      data-testid="router-delete-btn"
                      className="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded transition-colors"
                      title="Delete Router"
                      aria-label="Delete Router"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </td>
              </tr>
              {billingResult?.id === router.id && (
                <tr>
                  <td colSpan={6} className={`px-6 py-3 text-sm ${billingResult.success ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200' : 'bg-red-50 text-red-800 dark:bg-red-900/20 dark:text-red-200'}`}>
                    {billingResult.message}
                  </td>
                </tr>
              )}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>
      
      {/* Test Result Toast */}
      {testResult && (
        <div className={`m-4 p-4 rounded-lg border ${
          testResult.success 
            ? 'bg-green-50 border-green-200 dark:bg-green-900/20 dark:border-green-800' 
            : 'bg-red-50 border-red-200 dark:bg-red-900/20 dark:border-red-800'
        }`}>
          <p className={`text-sm font-medium ${
            testResult.success ? 'text-green-800 dark:text-green-200' : 'text-red-800 dark:text-red-200'
          }`}>
            {testResult.message}
          </p>
        </div>
      )}
      
      {/* Setup Script Modal */}
      {selectedRouter && (
        <SetupScriptModal
          isOpen={scriptModalOpen}
          onClose={() => setScriptModalOpen(false)}
          routerId={selectedRouter.id}
          routerName={selectedRouter.name}
        />
      )}
      {consoleRouter && (
        <RouterConsoleModal
          isOpen={true}
          onClose={() => setConsoleRouter(null)}
          routerId={consoleRouter.id}
          routerName={consoleRouter.name}
        />
      )}
      {provisioningRouter && (
        <RouterProvisioningModal
          isOpen={true}
          router={provisioningRouter}
          onClose={() => setProvisioningRouter(null)}
        />
      )}
    </div>
  );
}
