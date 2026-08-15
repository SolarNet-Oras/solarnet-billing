import { useCallback, useEffect, useMemo, useState } from 'react';
import { Activity, CheckCircle2, Gauge, Loader2, RotateCcw, ShieldAlert, SlidersHorizontal, Zap } from 'lucide-react';
import { routerService, type Router, type RouterQosDeployment, type RouterQosInspection, type RouterQosMetrics, type RouterQosPreview } from '@/services/routerService';

interface RouterQosMonitorProps {
  routers: Router[];
}

const formatRate = (bps: number | null | undefined) => {
  if (bps === null || bps === undefined) return 'Awaiting sample';
  if (bps < 1_000) return `${bps} bps`;
  if (bps < 1_000_000) return `${(bps / 1_000).toFixed(1)} Kbps`;
  return `${(bps / 1_000_000).toFixed(1)} Mbps`;
};

export function RouterQosMonitor({ routers }: RouterQosMonitorProps) {
  const [routerId, setRouterId] = useState('');
  const [inspection, setInspection] = useState<RouterQosInspection | null>(null);
  const [deployments, setDeployments] = useState<RouterQosDeployment[]>([]);
  const [metrics, setMetrics] = useState<RouterQosMetrics | null>(null);
  const [clients, setClients] = useState<Array<any>>([]);
  const [clientWarning, setClientWarning] = useState<string | null>(null);
  const [preview, setPreview] = useState<RouterQosPreview | null>(null);
  const [previewDeployment, setPreviewDeployment] = useState<RouterQosDeployment | null>(null);
  const [loading, setLoading] = useState(false);
  const [action, setAction] = useState<string | null>(null);
  const [message, setMessage] = useState<{ success: boolean; text: string } | null>(null);
  const [downloadCapacity, setDownloadCapacity] = useState('');
  const [uploadCapacity, setUploadCapacity] = useState('');
  const [ceiling, setCeiling] = useState('95');
  const [downloadParent, setDownloadParent] = useState('');
  const [uploadParent, setUploadParent] = useState('');
  const [testTarget, setTestTarget] = useState('1.1.1.1');
  const [testResult, setTestResult] = useState<{ latency_ms: number | null; packet_loss_percent: number; target: string } | null>(null);

  const selectedRouter = useMemo(() => routers.find((router) => router.id === routerId) ?? null, [routerId, routers]);
  const activeDeployment = deployments.find((deployment) => deployment.status === 'active') ?? null;
  const interfaceNames = inspection?.interfaces.filter((item) => item.running && !item.disabled).map((item) => item.name) ?? [];

  useEffect(() => {
    if (!routerId && routers.length) setRouterId(routers[0].id);
  }, [routerId, routers]);

  const load = useCallback(async (withClients = true) => {
    if (!routerId) return;
    try {
      setLoading(true);
      const [status, configuration, currentMetrics, customerQueues] = await Promise.all([
        routerService.qosStatus(routerId),
        routerService.qosConfig(routerId),
        routerService.qosMetrics(routerId),
        withClients ? routerService.qosClients(routerId) : Promise.resolve(null),
      ]);
      setInspection(status.inspection);
      setDeployments(configuration);
      setMetrics(currentMetrics);
      if (customerQueues) {
        setClients(customerQueues.data);
        setClientWarning(customerQueues.queue_read_warning);
      }
      const clientParent = status.inspection.client_interfaces[0] || status.inspection.bridge_interfaces[0] || status.inspection.vlan_interfaces[0] || '';
      const knownWan = status.inspection.wan_candidates.find((wan) => wan.interface)?.interface || '';
      setDownloadParent((current) => current || clientParent);
      setUploadParent((current) => current || knownWan);
    } catch (error: any) {
      setMessage({ success: false, text: error?.response?.data?.message || 'Could not read live RouterOS QoS data.' });
    } finally {
      setLoading(false);
    }
  }, [routerId]);

  useEffect(() => {
    setInspection(null); setDeployments([]); setMetrics(null); setClients([]); setPreview(null); setPreviewDeployment(null); setMessage(null); setDownloadParent(''); setUploadParent('');
    if (routerId) void load();
  }, [routerId, load]);

  useEffect(() => {
    if (!routerId) return;
    const interval = window.setInterval(async () => {
      try { setMetrics(await routerService.qosMetrics(routerId)); } catch { /* keep the last actual sample visible */ }
    }, 5_000);
    return () => window.clearInterval(interval);
  }, [routerId]);

  const handlePreview = async () => {
    if (!selectedRouter) return;
    try {
      setAction('preview'); setMessage(null);
      const result = await routerService.qosPreview(selectedRouter.id, {
        download_capacity_mbps: Number(downloadCapacity), upload_capacity_mbps: Number(uploadCapacity), ceiling_percent: Number(ceiling), download_parent: downloadParent, upload_parent: uploadParent,
      });
      setPreview(result.data.preview); setPreviewDeployment(result.data.deployment);
      setMessage({ success: result.data.preview.ready, text: result.message });
      await load(false);
    } catch (error: any) {
      setMessage({ success: false, text: error?.response?.data?.message || 'Could not create a QoS preview.' });
    } finally { setAction(null); }
  };

  const handleApply = async () => {
    if (!selectedRouter || !previewDeployment || !preview?.ready) return;
    if (!window.confirm(`Apply QoS to ${selectedRouter.name}?\n\nSolarNet will first require and verify a RouterOS backup, then create only two SolarNet-QoS:v1 queue trees. Existing customer Simple Queues, firewall rules, mangle rules, VLANs, DHCP, and routes are not changed.`)) return;
    try {
      setAction('apply'); setMessage(null);
      const result = await routerService.qosApply(selectedRouter.id, previewDeployment.id);
      setMessage({ success: true, text: result.message }); setPreview(null); setPreviewDeployment(null); await load();
    } catch (error: any) {
      setMessage({ success: false, text: error?.response?.data?.message || 'QoS was not applied.' }); await load(false);
    } finally { setAction(null); }
  };

  const handleDisable = async () => {
    if (!selectedRouter || !window.confirm(`Emergency disable SolarNet QoS on ${selectedRouter.name}?\n\nOnly queue trees marked SolarNet-QoS:v1 are removed. Customer plan queues and all unrelated RouterOS configuration stay in place.`)) return;
    try { setAction('disable'); const result = await routerService.qosDisable(selectedRouter.id); setMessage({ success: true, text: result.message }); await load(); }
    catch (error: any) { setMessage({ success: false, text: error?.response?.data?.message || 'QoS could not be disabled.' }); }
    finally { setAction(null); }
  };

  const handleRollback = async () => {
    if (!selectedRouter || !activeDeployment || !window.confirm(`Roll back SolarNet QoS version ${activeDeployment.configuration_version}?\n\nThis removes only SolarNet-QoS:v1 queue trees. The verified RouterOS backup remains recorded on the router.`)) return;
    try { setAction('rollback'); const result = await routerService.qosRollback(selectedRouter.id, activeDeployment.id); setMessage({ success: true, text: result.message }); await load(); }
    catch (error: any) { setMessage({ success: false, text: error?.response?.data?.message || 'QoS rollback failed.' }); }
    finally { setAction(null); }
  };

  const handleTest = async () => {
    if (!selectedRouter) return;
    try { setAction('test'); const result = await routerService.qosTest(selectedRouter.id, testTarget); setTestResult(result); }
    catch (error: any) { setMessage({ success: false, text: error?.response?.data?.message || 'QoS ping test failed.' }); }
    finally { setAction(null); }
  };

  if (routers.length === 0) return null;

  return <section className="mt-5 rounded-2xl border border-indigo-400/25 bg-slate-950/75 p-5 text-slate-100">
    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"><div><div className="flex items-center gap-2"><Gauge className="h-5 w-5 text-indigo-300" /><h3 className="font-semibold text-indigo-100">QoS monitor & safe deployment</h3></div><p className="mt-1 max-w-3xl text-xs text-slate-400">Read the actual router first. SolarNet preserves billing-created client Simple Queues and never overwrites firewall, mangle, VLAN, DHCP, routing, WireGuard, or failover configuration.</p></div><div className="flex items-center gap-2"><label className="text-xs text-slate-400" htmlFor="qos-router">Router</label><select id="qos-router" value={routerId} onChange={(event) => setRouterId(event.target.value)} className="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white">{routers.map((router) => <option key={router.id} value={router.id}>{router.name}</option>)}</select><button onClick={() => void load()} disabled={loading} className="rounded-lg border border-indigo-400/30 px-3 py-2 text-xs font-semibold text-indigo-100 hover:bg-indigo-500/10 disabled:opacity-50">{loading ? 'Reading...' : 'Inspect router'}</button></div></div>

    {message && <div className={`mt-4 rounded-lg border p-3 text-sm ${message.success ? 'border-emerald-400/25 bg-emerald-500/10 text-emerald-100' : 'border-red-400/25 bg-red-500/10 text-red-100'}`}>{message.text}</div>}

    {inspection && <>
      <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5"><Metric label="CPU" value={`${inspection.cpu_load}%`} alert={inspection.cpu_load > 80} /><Metric label="Memory" value={metrics?.memory_used_percent === null || metrics?.memory_used_percent === undefined ? 'Live read pending' : `${metrics.memory_used_percent}% used`} alert={(metrics?.memory_used_percent ?? 0) > 85} /><Metric label="Connections" value={String(metrics?.active_connections ?? inspection.active_connections)} /><Metric label="Customer queues" value={String(inspection.existing_queues.billing_customer_queues)} /><Metric label="QoS state" value={activeDeployment ? `Active v${activeDeployment.configuration_version}` : 'Not deployed'} alert={Boolean(activeDeployment)} /></div>

      <div className="mt-4 grid gap-4 xl:grid-cols-[1.05fr_0.95fr]"><article className="rounded-xl border border-slate-800 bg-slate-900/60 p-4"><h4 className="flex items-center gap-2 font-semibold text-white"><ShieldAlert className="h-4 w-4 text-amber-300" /> Safety discovery</h4><div className="mt-3 grid gap-2 text-xs text-slate-300 sm:grid-cols-2"><Info label="RouterOS" value={`${inspection.routeros_version || 'unknown'} · ${inspection.board_name || 'unknown board'}`} /><Info label="FastTrack" value={inspection.fasttrack.enabled ? `Enabled (${inspection.fasttrack.count}) - deployment blocked` : 'Not detected'} /><Info label="Queue architecture" value={`${inspection.existing_queues.simple_total} simple / ${inspection.existing_queues.queue_tree_total} tree`} /><Info label="Capabilities" value={`FQ-CoDel: ${inspection.queue_capabilities.fq_codel.join(', ') || 'none'} · PCQ: ${inspection.queue_capabilities.pcq.join(', ') || 'none'}`} /><Info label="WAN routes" value={inspection.multi_wan_detected ? 'Multiple paths - explicit WAN mapping required' : `${inspection.wan_candidates.length || 0} default path`} /><Info label="Client network" value={`${inspection.client_interfaces.join(', ') || 'not detected'} · ${inspection.dhcp_lease_count} DHCP leases`} /></div>{inspection.warnings.length > 0 && <ul className="mt-3 space-y-1 rounded-lg bg-amber-500/5 p-3 text-xs text-amber-100">{inspection.warnings.map((warning) => <li key={warning}>- {warning}</li>)}</ul>}</article>

        <article className="rounded-xl border border-slate-800 bg-slate-900/60 p-4"><h4 className="flex items-center gap-2 font-semibold text-white"><Activity className="h-4 w-4 text-sky-300" /> Live QoS health</h4><div className="mt-3 grid grid-cols-2 gap-2 text-xs"><Info label="Router RX" value={formatRate(metrics?.rx_bps)} /><Info label="Router TX" value={formatRate(metrics?.tx_bps)} /><Info label="Queue drops" value={`${metrics?.queue_drops ?? '—'}${metrics?.queue_drop_delta ? ` (+${metrics.queue_drop_delta})` : ''}`} /><Info label="Latency / loss" value={testResult ? `${testResult.latency_ms ?? 'timeout'} ms / ${testResult.packet_loss_percent}%` : 'Run a real test'} /></div><div className="mt-4 flex gap-2"><input value={testTarget} onChange={(event) => setTestTarget(event.target.value)} aria-label="QoS test target" className="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-white" /><button onClick={() => void handleTest()} disabled={action === 'test'} className="inline-flex items-center gap-2 rounded-lg border border-sky-400/30 px-3 py-2 text-xs font-semibold text-sky-100 hover:bg-sky-500/10 disabled:opacity-50">{action === 'test' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Zap className="h-3.5 w-3.5" />} Test QoS</button></div>{(metrics?.warnings || []).length > 0 && <p className="mt-3 text-xs text-amber-200">{metrics?.warnings.join(' ')}</p>}</article></div>

      <article className="mt-4 rounded-xl border border-indigo-400/20 bg-indigo-500/5 p-4"><div className="flex items-center gap-2"><SlidersHorizontal className="h-4 w-4 text-indigo-200" /><h4 className="font-semibold text-indigo-100">Preview configuration</h4></div><p className="mt-1 text-xs text-slate-400">Use measured usable capacity, not advertised speed. The default 95% ceiling keeps queueing on the router. This preview makes no RouterOS change.</p><div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5"><Field label="Download capacity Mbps" value={downloadCapacity} setValue={setDownloadCapacity} type="number" /><Field label="Upload capacity Mbps" value={uploadCapacity} setValue={setUploadCapacity} type="number" /><Field label="Ceiling %" value={ceiling} setValue={setCeiling} type="number" /><InterfaceSelect label="Download parent" value={downloadParent} onChange={setDownloadParent} interfaces={interfaceNames} /><InterfaceSelect label="Upload WAN parent" value={uploadParent} onChange={setUploadParent} interfaces={interfaceNames} /></div><div className="mt-4 flex flex-wrap gap-2"><button onClick={() => void handlePreview()} disabled={action === 'preview'} className="inline-flex items-center gap-2 rounded-lg bg-indigo-500 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-400 disabled:opacity-50">{action === 'preview' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <SlidersHorizontal className="h-3.5 w-3.5" />} Preview only</button><button onClick={() => void handleApply()} disabled={!preview?.ready || !previewDeployment || action === 'apply'} className="inline-flex items-center gap-2 rounded-lg bg-emerald-500 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40">{action === 'apply' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="h-3.5 w-3.5" />} Apply QoS</button><button onClick={() => void handleRollback()} disabled={!activeDeployment || action === 'rollback'} className="inline-flex items-center gap-2 rounded-lg border border-amber-400/35 px-3 py-2 text-xs font-semibold text-amber-100 hover:bg-amber-500/10 disabled:opacity-40"><RotateCcw className="h-3.5 w-3.5" /> Rollback</button><button onClick={() => void handleDisable()} disabled={!activeDeployment || action === 'disable'} className="rounded-lg border border-red-400/35 px-3 py-2 text-xs font-semibold text-red-100 hover:bg-red-500/10 disabled:opacity-40">Disable QoS</button></div>
        {preview && <div className={`mt-4 rounded-lg border p-3 text-xs ${preview.ready ? 'border-emerald-400/25 bg-emerald-500/10 text-emerald-100' : 'border-amber-400/25 bg-amber-500/10 text-amber-100'}`}><p className="font-semibold">{preview.ready ? 'Preview ready for manual administrator confirmation' : 'Preview refused - no router change was made'}</p>{preview.ready ? <><p className="mt-1">{preview.recommendation.reason}</p><p className="mt-2">Creates {preview.preservation.queue_trees_to_create} queue trees using {preview.recommendation.queue_type}; download {preview.configuration.download_limit}, upload {preview.configuration.upload_limit}.</p><p className="mt-1">Preserves {preview.preservation.customer_simple_queues_preserved} billing customer queues; firewall/mangle changes: 0 / 0.</p></> : <ul className="mt-2 space-y-1">{preview.errors.map((error) => <li key={error}>- {error}</li>)}</ul>}</div>}</article>

      <article className="mt-4 rounded-xl border border-slate-800 bg-slate-900/60 p-4"><div className="flex items-center justify-between gap-3"><div><h4 className="font-semibold text-white">Existing customer plan queues</h4><p className="text-xs text-slate-400">Read-only queue/plan reference. QoS does not replace these limits.</p></div><span className="text-xs text-slate-500">{clients.length} registered client{clients.length === 1 ? '' : 's'}</span></div>{clientWarning && <p className="mt-2 text-xs text-amber-200">{clientWarning}</p>}<div className="mt-3 max-h-64 overflow-auto rounded-lg border border-slate-800"><table className="w-full text-left text-xs"><thead className="sticky top-0 bg-slate-950 text-slate-400"><tr><th className="px-3 py-2">Client</th><th className="px-3 py-2">Plan limit</th><th className="px-3 py-2">Current traffic</th><th className="px-3 py-2">Queue</th></tr></thead><tbody>{clients.slice(0, 100).map((client) => <tr key={client.customer_id} className="border-t border-slate-800"><td className="px-3 py-2"><p className="font-medium text-slate-100">{client.full_name}</p><p className="text-slate-500">{client.ip_address || 'No IP'}</p></td><td className="px-3 py-2">{client.plan ? <><p>{client.plan.download_speed}/{client.plan.upload_speed} Mbps</p><p className="text-slate-500">{client.plan.qos_priority_level}</p></> : 'No plan'}</td><td className="px-3 py-2">{client.queue?.rate || 'Queue not found'}</td><td className="px-3 py-2">{client.queue?.name || '—'}</td></tr>)}</tbody></table></div></article>

      {deployments.length > 0 && <article className="mt-4 rounded-xl border border-slate-800 bg-slate-900/60 p-4"><h4 className="font-semibold text-white">QoS configuration history</h4><div className="mt-3 space-y-2">{deployments.slice(0, 5).map((deployment) => <div key={deployment.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-800 p-3 text-xs"><span className="font-semibold text-slate-100">v{deployment.configuration_version} · {deployment.status}</span><span className="text-slate-400">{deployment.strategy || 'No strategy'} · {deployment.queue_type || 'No queue type'}</span><span className="text-slate-500">Backup: {deployment.backup_filename || 'Not created'}</span></div>)}</div></article>}
    </>}
  </section>;
}

function Metric({ label, value, alert = false }: { label: string; value: string; alert?: boolean }) { return <div className={`rounded-lg border p-3 ${alert ? 'border-amber-400/25 bg-amber-500/10' : 'border-slate-800 bg-slate-900/60'}`}><p className="text-[11px] uppercase tracking-wide text-slate-400">{label}</p><p className={`mt-1 text-sm font-semibold ${alert ? 'text-amber-100' : 'text-white'}`}>{value}</p></div>; }
function Info({ label, value }: { label: string; value: string }) { return <div><p className="text-slate-500">{label}</p><p className="mt-0.5 text-slate-200">{value}</p></div>; }
function Field({ label, value, setValue, type }: { label: string; value: string; setValue: (value: string) => void; type: 'number' | 'text' }) { return <label className="block text-xs text-slate-400">{label}<input type={type} min={type === 'number' ? '0' : undefined} value={value} onChange={(event) => setValue(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white" /></label>; }
function InterfaceSelect({ label, value, onChange, interfaces }: { label: string; value: string; onChange: (value: string) => void; interfaces: string[] }) { return <label className="block text-xs text-slate-400">{label}<select value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"><option value="">Select interface</option>{interfaces.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>; }
