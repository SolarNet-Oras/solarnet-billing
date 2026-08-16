import { useState } from 'react';
import { AlertTriangle, CheckCircle2, ClipboardCheck, Loader2, Network, Plus, RefreshCw, Trash2, X } from 'lucide-react';
import {
  type Router,
  type RouterDnsBrandingAudit,
  type RouterDnsBrandingDiscovery,
  type RouterDnsBrandingInput,
  type RouterDnsBrandingPlan,
  routerService,
} from '@/services/routerService';

const CONFIRMATION = 'I approve SolarNet internal DNS branding on this router.';

type EditableRecord = RouterDnsBrandingInput['records'][number];

const blankRecord = (hostname = ''): EditableRecord => ({ hostname, type: 'A', address: '', ttl: 86400, description: '' });

interface RouterDnsBrandingModalProps {
  isOpen: boolean;
  router: Router;
  onClose: () => void;
}

/**
 * This modal intentionally exposes a staged workflow rather than a one-click
 * DNS toggle. The server remains the authority for ownership and safety checks.
 */
export function RouterDnsBrandingModal({ isOpen, router, onClose }: RouterDnsBrandingModalProps) {
  const [busy, setBusy] = useState<'discover' | 'preview' | 'backup' | 'test' | 'apply' | 'rollback' | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [discovery, setDiscovery] = useState<RouterDnsBrandingDiscovery | null>(null);
  const [audit, setAudit] = useState<RouterDnsBrandingAudit | null>(null);
  const [plan, setPlan] = useState<RouterDnsBrandingPlan | null>(null);
  const [domain, setDomain] = useState('solarnet.local');
  const [records, setRecords] = useState<EditableRecord[]>([blankRecord('router'), blankRecord('billing'), blankRecord('portal'), blankRecord('speedtest'), blankRecord('dns')]);
  const [approvedNetworks, setApprovedNetworks] = useState<string[]>([]);
  const [removeRecordIds, setRemoveRecordIds] = useState<string[]>([]);
  const [confirmation, setConfirmation] = useState('');
  const [tests, setTests] = useState<Array<{ hostname: string; address: string | null; ok: boolean; message: string }> | null>(null);

  if (!isOpen) return null;

  const errorMessage = (error: any, fallback: string) => error?.response?.data?.message || error?.message || fallback;
  const timeoutMessage = (error: any) => error?.code === 'ECONNABORTED' || /timeout/i.test(String(error?.message || ''));

  const discover = async () => {
    setBusy('discover');
    setMessage(null);
    setPlan(null);
    setTests(null);
    setConfirmation('');
    try {
      const result = await routerService.dnsBrandingDiscover(router.id);
      const managementIp = result.discovery.router_management_candidates[0]?.address || '';
      setDiscovery(result.discovery);
      setAudit(result.audit);
      setDomain(result.discovery.default_domain || 'solarnet.local');
      setRecords((current) => current.map((record) => (
        (record.hostname === 'router' || record.hostname === 'dns') && !record.address
          ? { ...record, address: managementIp, description: record.hostname === 'router' ? 'Router management address' : 'RouterOS internal DNS resolver' }
          : record
      )));
      setMessage(result.message);
    } catch (error: any) {
      setMessage(timeoutMessage(error)
        ? 'DNS discovery exceeded the two-minute read-only browser limit. No RouterOS configuration was changed. Test the router connection and VPN/port-forward, then try again.'
        : errorMessage(error, 'DNS discovery failed. No RouterOS configuration was changed.'));
    } finally {
      setBusy(null);
    }
  };

  const preview = async () => {
    if (!audit) return;
    setBusy('preview');
    setMessage(null);
    setTests(null);
    try {
      const payload: RouterDnsBrandingInput & { audit_id: string } = {
        audit_id: audit.id,
        domain,
        records,
        approved_dhcp_network_ids: approvedNetworks,
        remove_record_ids: removeRecordIds,
      };
      const result = await routerService.dnsBrandingPreview(router.id, payload);
      setAudit(result.audit);
      setPlan(result.plan);
      setMessage(result.message);
    } catch (error: any) {
      setMessage(errorMessage(error, 'DNS preview was not generated. No RouterOS configuration was changed.'));
    } finally {
      setBusy(null);
    }
  };

  const backup = async () => {
    if (!audit) return;
    setBusy('backup');
    setMessage(null);
    try {
      const result = await routerService.dnsBrandingBackup(router.id, audit.id);
      setAudit(result.audit);
      setMessage(`${result.message} File: ${result.audit.backup_filename || 'verified by RouterOS'}.`);
    } catch (error: any) {
      setMessage(errorMessage(error, 'DNS backup failed. Apply remains blocked.'));
    } finally {
      setBusy(null);
    }
  };

  const test = async () => {
    if (!audit) return;
    setBusy('test');
    setMessage(null);
    try {
      const result = await routerService.dnsBrandingTest(router.id, audit.id);
      setTests(result.results);
      setMessage(result.message);
    } catch (error: any) {
      setMessage(errorMessage(error, 'The read-only DNS test could not be completed.'));
    } finally {
      setBusy(null);
    }
  };

  const apply = async () => {
    if (!audit || confirmation !== CONFIRMATION) return;
    setBusy('apply');
    setMessage(null);
    try {
      const result = await routerService.dnsBrandingApply(router.id, audit.id, confirmation);
      setAudit(result.audit);
      setMessage(result.message);
      await test();
    } catch (error: any) {
      setMessage(errorMessage(error, 'DNS branding was not completed. Review the audit before retrying.'));
    } finally {
      setBusy(null);
    }
  };

  const rollback = async () => {
    if (!audit || !confirm('Roll back only this audit\'s SolarNet-DNS:v1 records and its DHCP DNS values? Unknown DNS records will stay untouched.')) return;
    setBusy('rollback');
    setMessage(null);
    try {
      const result = await routerService.dnsBrandingRollback(router.id, audit.id);
      setAudit(result.audit);
      setMessage(result.message);
    } catch (error: any) {
      setMessage(errorMessage(error, 'DNS rollback needs review. Do not retry repeatedly; inspect the audit and verified backup.'));
    } finally {
      setBusy(null);
    }
  };

  const updateRecord = (index: number, patch: Partial<EditableRecord>) => setRecords((current) => current.map((record, row) => row === index ? { ...record, ...patch } : record));
  const toggleNetwork = (id: string) => setApprovedNetworks((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id]);
  const toggleRemoval = (id: string) => setRemoveRecordIds((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id]);
  const fieldClass = 'mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-cyan-500';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
      <div className="flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-border bg-card shadow-2xl" onClick={(event) => event.stopPropagation()}>
        <header className="flex items-start justify-between gap-4 border-b border-border p-5">
          <div className="flex gap-3">
            <div className="rounded-xl bg-cyan-500/15 p-2.5 text-cyan-700 dark:text-cyan-300"><Network className="h-6 w-6" /></div>
            <div>
              <h2 className="font-bold text-foreground">DNS Management</h2>
              <p className="text-sm text-muted-foreground">{router.name} · internal SolarNet DNS branding only</p>
            </div>
          </div>
          <button type="button" onClick={onClose} className="rounded p-2 text-muted-foreground hover:bg-secondary hover:text-foreground" aria-label="Close DNS management"><X className="h-5 w-5" /></button>
        </header>

        <main className="overflow-y-auto p-5">
          <section className="mb-5 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-950 dark:text-amber-100">
            <div className="flex gap-2 font-semibold"><AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />Internal naming only</div>
            <p className="mt-1">Internal DNS branding does not change or hide the router&apos;s public IP from external Internet services. This workflow never changes WAN, public IP, NAT, routing, firewall, VLANs, QoS, or billing.</p>
          </section>

          <div className="mb-5 grid gap-2 text-xs sm:grid-cols-5">
            {['1. Scan router', '2. Select records', '3. Preview', '4. Backup', '5. Approve & verify'].map((label, index) => (
              <div key={label} className={`rounded-md border px-3 py-2 ${(!audit && index === 0) || (audit && index < (plan ? 4 : 2)) || audit?.status === 'verified' ? 'border-cyan-500/40 bg-cyan-500/10 text-cyan-900 dark:text-cyan-100' : 'border-border text-muted-foreground'}`}>{label}</div>
            ))}
          </div>

          {message && <div className={`mb-5 flex gap-2 rounded-lg border p-3 text-sm ${/failed|blocked|not |rollback needs|could not/i.test(message) ? 'border-red-500/40 bg-red-500/10 text-red-900 dark:text-red-100' : 'border-cyan-500/30 bg-cyan-500/10 text-cyan-900 dark:text-cyan-100'}`}><ClipboardCheck className="mt-0.5 h-4 w-4 shrink-0" /><span>{message}</span></div>}

          {!discovery && (
            <section className="rounded-xl border border-border p-5">
              <h3 className="font-semibold text-foreground">Read-only DNS compatibility scan</h3>
              <p className="mt-1 text-sm text-muted-foreground">Reads RouterOS DNS configuration, static records, DoH, DHCP servers/networks, and customer VLAN context. Existing records remain protected.</p>
              <button type="button" onClick={() => void discover()} disabled={busy !== null} className="mt-4 inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-50" data-testid="router-dns-discover">
                {busy === 'discover' ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />} Scan Router
              </button>
            </section>
          )}

          {discovery && audit && (
            <div className="space-y-5">
              <section className="rounded-xl border border-border p-4">
                <div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="font-semibold text-foreground">MikroTik DNS scan</h3><p className="mt-1 text-sm text-muted-foreground">Upstream DNS: {[...discovery.dns.servers, ...discovery.dns.dynamic_servers, discovery.dns.use_doh_server].filter(Boolean).join(', ') || 'not detected'}</p></div><button type="button" onClick={() => void discover()} disabled={busy !== null} className="inline-flex items-center gap-2 rounded-md border border-input px-3 py-2 text-xs font-semibold text-foreground hover:bg-secondary disabled:opacity-50"><RefreshCw className="h-3.5 w-3.5" /> Rescan</button></div>
                <div className="mt-4 grid gap-3 text-sm md:grid-cols-3"><StatusCard label="RouterOS DNS" value={discovery.allow_remote_requests ? 'Available to LAN clients' : 'Remote requests disabled'} ok={discovery.allow_remote_requests} /><StatusCard label="Upstream DNS" value={discovery.upstream_dns_available ? 'Detected' : 'Not detected'} ok={discovery.upstream_dns_available} /><StatusCard label="Protected custom records" value={`${discovery.compatibility.unknown_static_records_protected}`} ok /></div>
                {!discovery.allow_remote_requests && <p className="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-950 dark:text-amber-100">DHCP DNS distribution is intentionally blocked while RouterOS remote DNS requests are disabled. SolarNet will not enable it or alter firewall input rules automatically.</p>}
                {discovery.dns_policy.optional_read_errors.length > 0 && <p className="mt-3 text-xs text-muted-foreground">DNS adlist/policy information is unavailable on this RouterOS version; it was not changed.</p>}
              </section>

              <section className="rounded-xl border border-border p-4">
                <div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="font-semibold text-foreground">SolarNet internal records</h3><p className="mt-1 text-sm text-muted-foreground">A and AAAA records are supported. CNAME remains disabled until it has a separately reviewed safety policy.</p></div><button type="button" onClick={() => setRecords((current) => [...current, blankRecord()])} className="inline-flex items-center gap-2 rounded-md border border-input px-3 py-2 text-xs font-semibold text-foreground hover:bg-secondary"><Plus className="h-3.5 w-3.5" /> Add DNS Record</button></div>
                <label className="mt-4 block text-sm font-medium text-foreground">Internal domain<input className={fieldClass} value={domain} onChange={(event) => { setDomain(event.target.value); setPlan(null); }} placeholder="solarnet.local" /></label>
                <div className="mt-4 space-y-3">
                  {records.map((record, index) => <div key={index} className="grid gap-3 rounded-lg border border-border bg-muted/20 p-3 md:grid-cols-[minmax(0,1fr)_110px_minmax(0,1.2fr)_110px_minmax(0,1fr)_36px]"><label className="text-xs font-medium text-muted-foreground">Hostname<input className={fieldClass} value={record.hostname} onChange={(event) => updateRecord(index, { hostname: event.target.value })} placeholder="router" /></label><label className="text-xs font-medium text-muted-foreground">Type<select className={fieldClass} value={record.type} onChange={(event) => updateRecord(index, { type: event.target.value as EditableRecord['type'] })}><option value="A">A</option><option value="AAAA">AAAA</option><option value="CNAME" disabled>CNAME (coming later)</option></select></label><label className="text-xs font-medium text-muted-foreground">IP address<input className={fieldClass} value={record.address} onChange={(event) => updateRecord(index, { address: event.target.value })} placeholder={record.type === 'AAAA' ? '2001:db8::10' : '192.168.88.1'} /></label><label className="text-xs font-medium text-muted-foreground">TTL seconds<input className={fieldClass} type="number" min="60" max="604800" value={record.ttl} onChange={(event) => updateRecord(index, { ttl: Number(event.target.value) })} /></label><label className="text-xs font-medium text-muted-foreground">Description<input className={fieldClass} value={record.description} onChange={(event) => updateRecord(index, { description: event.target.value })} placeholder="Optional" /></label><button type="button" onClick={() => setRecords((current) => current.filter((_, row) => row !== index))} disabled={records.length === 1} className="mt-5 rounded p-2 text-red-600 hover:bg-red-50 disabled:opacity-30 dark:hover:bg-red-900/20" aria-label="Remove DNS record"><Trash2 className="h-4 w-4" /></button></div>)}
                </div>
              </section>

              <section className="rounded-xl border border-border p-4"><h3 className="font-semibold text-foreground">Approved customer DHCP networks</h3><p className="mt-1 text-sm text-muted-foreground">Nothing is selected automatically. Selecting a network changes only that DHCP network&apos;s DNS server to its existing MikroTik gateway after final approval.</p><div className="mt-4 space-y-2">{discovery.dhcp_networks.map((network) => <label key={network.id || `${network.server_name}-${network.interface}`} className={`flex gap-3 rounded-lg border p-3 text-sm ${network.manageable ? 'border-border hover:bg-muted/30' : 'border-amber-500/30 bg-amber-500/5 text-muted-foreground'}`}><input type="checkbox" disabled={!network.manageable || !network.id || !discovery.allow_remote_requests} checked={!!network.id && approvedNetworks.includes(network.id)} onChange={() => network.id && toggleNetwork(network.id)} className="mt-0.5 h-4 w-4" /><span className="flex-1"><strong className="text-foreground">{network.server_name || 'Unnamed DHCP'} · {network.interface || 'unknown interface'}</strong><br /><span className="text-muted-foreground">{network.network || 'Unknown subnet'} · Gateway {network.gateway || 'unknown'} · Current DNS {network.dns_server || 'not set'}{network.vlan_id ? ` · VLAN ${network.vlan_id}` : ''}</span>{!network.manageable && <span className="mt-1 block text-xs">Protected: this DHCP network is disabled, incomplete, or not a known private customer gateway.</span>}</span></label>)}</div></section>

              {discovery.static_records.filter((record) => record.owned_by_solarnet).length > 0 && <section className="rounded-xl border border-border p-4"><h3 className="font-semibold text-foreground">Existing SolarNet-owned records</h3><p className="mt-1 text-sm text-muted-foreground">Unknown/custom DNS records are not listed here and cannot be removed by SolarNet.</p><div className="mt-3 space-y-2">{discovery.static_records.filter((record) => record.owned_by_solarnet).map((record) => <label key={record.id || record.name} className="flex items-center gap-3 rounded-lg border border-border p-3 text-sm"><input type="checkbox" checked={!!record.id && removeRecordIds.includes(record.id)} onChange={() => record.id && toggleRemoval(record.id)} /><span><strong className="text-foreground">{record.name}</strong> → {record.address} <span className="text-muted-foreground">({record.comment})</span></span></label>)}</div></section>}

              <div className="flex flex-wrap gap-3"><button type="button" onClick={() => void preview()} disabled={busy !== null} className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-50" data-testid="router-dns-preview">{busy === 'preview' && <Loader2 className="h-4 w-4 animate-spin" />} Preview Changes</button><button type="button" onClick={() => void test()} disabled={busy !== null} className="rounded-md border border-input px-4 py-2.5 text-sm font-semibold text-foreground hover:bg-secondary disabled:opacity-50">Test DNS</button></div>

              {plan && <section className="rounded-xl border border-cyan-500/35 bg-cyan-500/5 p-4"><div className="flex items-center gap-2"><ClipboardCheck className="h-5 w-5 text-cyan-600" /><h3 className="font-semibold text-foreground">DNS configuration preview</h3></div><div className="mt-4 grid gap-3 text-sm md:grid-cols-2"><StatusCard label="Internal domain" value={plan.domain} ok /><StatusCard label="DNS record changes" value={`${plan.record_changes.length} create/update · ${plan.record_removals.length} remove`} ok /><StatusCard label="DHCP DNS changes" value={`${plan.dhcp_changes.length} explicitly approved`} ok={plan.dhcp_changes.length > 0} /><StatusCard label="Protected custom records" value={`${plan.protected.unknown_static_records ?? 0} untouched`} ok /></div>{plan.warnings.map((warning) => <p key={warning} className="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-950 dark:text-amber-100">{warning}</p>)}<ul className="mt-4 list-disc space-y-1 pl-5 text-sm text-muted-foreground">{plan.record_changes.map((record) => <li key={record.hostname}>{record.action === 'replace_solarnet' ? 'Replace SolarNet-owned' : 'Add'} {record.hostname} → {record.address}</li>)}{plan.record_removals.map((record) => <li key={record.existing_id}>Remove SolarNet-owned {record.previous.name}</li>)}{plan.dhcp_changes.map((change) => <li key={change.network_id}>DHCP {change.server_name || change.network}: DNS {change.previous_dns_server || 'blank'} → {change.new_dns_server}</li>)}</ul><p className="mt-4 text-xs font-medium text-emerald-800 dark:text-emerald-200">WAN · Public IP · NAT · Routing · Firewall · VLAN · QoS · Billing: UNCHANGED</p><div className="mt-4 flex flex-wrap gap-3"><button type="button" onClick={() => void backup()} disabled={busy !== null} className="rounded-md border border-input px-4 py-2.5 text-sm font-semibold text-foreground hover:bg-secondary disabled:opacity-50">{busy === 'backup' && <Loader2 className="mr-2 inline h-4 w-4 animate-spin" />} Backup</button><button type="button" onClick={() => void test()} disabled={busy !== null} className="rounded-md border border-input px-4 py-2.5 text-sm font-semibold text-foreground hover:bg-secondary disabled:opacity-50">Test DNS</button></div><div className="mt-5 rounded-lg border border-amber-500/40 bg-amber-500/10 p-4"><h4 className="font-semibold text-amber-950 dark:text-amber-100">Administrator approval required</h4><p className="mt-1 text-sm text-amber-900 dark:text-amber-200">A fresh discovery and verified backup run again immediately before applying. If internal or external DNS verification fails, SolarNet removes only records tagged by this audit and restores only its approved DHCP DNS values.</p><label className="mt-3 block text-sm font-medium text-foreground">Type exactly:<span className="mt-2 block select-all rounded bg-background p-2 font-mono text-xs text-muted-foreground">{CONFIRMATION}</span><input className={fieldClass} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} placeholder="Type the confirmation sentence" /></label><button type="button" onClick={() => void apply()} disabled={busy !== null || confirmation !== CONFIRMATION} className="mt-3 inline-flex items-center gap-2 rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50" data-testid="router-dns-apply">{busy === 'apply' && <Loader2 className="h-4 w-4 animate-spin" />} Apply & Verify</button></div></section>}

              {audit.status === 'verified' && <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-4"><div className="flex gap-2 text-sm text-emerald-950 dark:text-emerald-100"><CheckCircle2 className="h-5 w-5 shrink-0" /><span><strong>Internal DNS verified.</strong> The audit and backup reference are retained for review.</span></div><button type="button" onClick={() => void rollback()} disabled={busy !== null} className="rounded-md border border-red-400 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50 dark:text-red-300 dark:hover:bg-red-900/20">{busy === 'rollback' && <Loader2 className="mr-2 inline h-4 w-4 animate-spin" />} Rollback</button></section>}

              {tests && <section className="rounded-xl border border-border p-4"><h3 className="font-semibold text-foreground">Read-only DNS test results</h3><div className="mt-3 space-y-2">{tests.map((result) => <div key={result.hostname} className={`rounded-lg border p-3 text-sm ${result.ok ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-amber-500/30 bg-amber-500/5'}`}><strong className="text-foreground">{result.hostname}</strong> → {result.address || 'not resolved'} <span className="text-muted-foreground">· {result.message}</span></div>)}</div></section>}
            </div>
          )}
        </main>
      </div>
    </div>
  );
}

function StatusCard({ label, value, ok }: { label: string; value: string; ok: boolean }) {
  return <div className={`rounded-lg border p-3 ${ok ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-amber-500/30 bg-amber-500/5'}`}><div className="text-xs uppercase tracking-wide text-muted-foreground">{label}</div><div className="mt-1 font-medium text-foreground">{value}</div></div>;
}
