import { useState } from 'react';
import { AlertTriangle, CheckCircle2, ClipboardCheck, Loader2, Network, ShieldAlert, Wifi, X } from 'lucide-react';
import {
  type Router,
  type RouterProvisioningAudit,
  type RouterProvisioningDiscovery,
  type RouterProvisioningPlan,
  routerService,
} from '@/services/routerService';

const CONFIRMATION = 'I understand this router will be configured as a SolarNet IPoE router.';

interface RouterProvisioningModalProps {
  isOpen: boolean;
  router: Router;
  onClose: () => void;
}

type Step = 'intro' | 'config' | 'preview' | 'complete';

export function RouterProvisioningModal({ isOpen, router, onClose }: RouterProvisioningModalProps) {
  const [step, setStep] = useState<Step>('intro');
  const [busy, setBusy] = useState<'discover' | 'preview' | 'apply' | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [discovery, setDiscovery] = useState<RouterProvisioningDiscovery | null>(null);
  const [audit, setAudit] = useState<RouterProvisioningAudit | null>(null);
  const [plan, setPlan] = useState<RouterProvisioningPlan | null>(null);
  const [confirmation, setConfirmation] = useState('');
  const [form, setForm] = useState({
    wan_interface: '',
    customer_parent_interface: '',
    customer_vlan_id: '100',
    customer_gateway_cidr: '10.100.0.1/24',
    customer_dhcp_pool: '10.100.0.10-10.100.0.254',
    dns_servers: '1.1.1.1,8.8.8.8',
    enable_captive_portal: false,
    portal_vlan_id: '200',
    portal_gateway_cidr: '10.200.0.1/24',
    portal_dhcp_pool: '10.200.0.10-10.200.0.254',
  });

  if (!isOpen) return null;

  const errorMessage = (error: any, fallback: string) => error?.response?.data?.message || error?.message || fallback;
  const isTimeout = (error: any) => error?.code === 'ECONNABORTED' || /timeout/i.test(String(error?.message || ''));

  const discover = async () => {
    setBusy('discover');
    setMessage(null);
    setPlan(null);
    setConfirmation('');
    try {
      const result = await routerService.provisioningDiscover(router.id);
      setDiscovery(result.discovery);
      setAudit(result.audit);
      setMessage(result.message);
      const autoWan = result.discovery.wan_candidates.length === 1 ? result.discovery.wan_candidates[0]?.interface || '' : '';
      const alternate = result.discovery.running_interfaces.find((name) => name !== autoWan) || '';
      setForm((current) => ({ ...current, wan_interface: autoWan, customer_parent_interface: alternate }));
      if (result.discovery.clean) setStep('config');
    } catch (error: any) {
      setMessage(isTimeout(error)
        ? 'Router discovery exceeded its two-minute read-only limit. No RouterOS configuration was changed. Test the router connection, then verify the VPN or port-forward is stable before trying again.'
        : errorMessage(error, 'Router discovery failed. No configuration was changed.'));
    } finally {
      setBusy(null);
    }
  };

  const preview = async () => {
    if (!audit) return;
    setBusy('preview');
    setMessage(null);
    try {
      const result = await routerService.provisioningPreview(router.id, {
        audit_id: audit.id,
        wan_interface: form.wan_interface,
        customer_parent_interface: form.customer_parent_interface,
        customer_vlan_id: Number(form.customer_vlan_id),
        customer_gateway_cidr: form.customer_gateway_cidr,
        customer_dhcp_pool: form.customer_dhcp_pool,
        dns_servers: form.dns_servers,
        enable_captive_portal: form.enable_captive_portal,
        ...(form.enable_captive_portal ? {
          portal_vlan_id: Number(form.portal_vlan_id),
          portal_gateway_cidr: form.portal_gateway_cidr,
          portal_dhcp_pool: form.portal_dhcp_pool,
        } : {}),
      });
      setAudit(result.audit);
      setPlan(result.plan);
      setMessage(result.message);
      setStep('preview');
    } catch (error: any) {
      setMessage(errorMessage(error, 'The plan could not be generated. No configuration was changed.'));
    } finally {
      setBusy(null);
    }
  };

  const apply = async () => {
    if (!audit || confirmation !== CONFIRMATION) return;
    setBusy('apply');
    setMessage(null);
    try {
      const result = await routerService.provisioningApply(router.id, audit.id, confirmation);
      setAudit(result.audit);
      setMessage(result.message);
      setStep('complete');
    } catch (error: any) {
      setMessage(errorMessage(error, 'Provisioning was not completed. Check the audit result before retrying.'));
    } finally {
      setBusy(null);
    }
  };

  const fieldClass = 'mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-primary';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
      <div className="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-border bg-card shadow-2xl" onClick={(event) => event.stopPropagation()}>
        <div className="flex items-start justify-between gap-4 border-b border-border p-5">
          <div className="flex gap-3">
            <div className="rounded-xl bg-cyan-500/15 p-2.5 text-cyan-600 dark:text-cyan-300"><Network className="h-6 w-6" /></div>
            <div>
              <h2 className="font-bold text-foreground">Set Up MikroTik</h2>
              <p className="text-sm text-muted-foreground">{router.name} · clean-router IPoE provisioning only</p>
            </div>
          </div>
          <button type="button" onClick={onClose} className="rounded p-2 text-muted-foreground hover:bg-secondary hover:text-foreground" aria-label="Close setup"><X className="h-5 w-5" /></button>
        </div>

        <div className="overflow-y-auto p-5">
          <div className="mb-5 grid gap-2 text-xs sm:grid-cols-4">
            {['1. Discover', '2. Plan', '3. Confirm', '4. Test IPoE client'].map((label, index) => {
              const active = (step === 'intro' && index === 0) || (step === 'config' && index <= 1) || (step === 'preview' && index <= 2) || (step === 'complete');
              return <div key={label} className={`rounded-md border px-3 py-2 ${active ? 'border-cyan-500/40 bg-cyan-500/10 text-cyan-800 dark:text-cyan-200' : 'border-border text-muted-foreground'}`}>{label}</div>;
            })}
          </div>

          {message && (
            <div className={`mb-5 flex gap-2 rounded-lg border p-3 text-sm ${discovery && !discovery.clean ? 'border-red-500/40 bg-red-500/10 text-red-800 dark:text-red-200' : 'border-cyan-500/30 bg-cyan-500/10 text-cyan-900 dark:text-cyan-100'}`}>
              {discovery && !discovery.clean ? <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" /> : <ClipboardCheck className="mt-0.5 h-4 w-4 shrink-0" />}
              <span>{message}</span>
            </div>
          )}

          {step === 'intro' && (
            <div className="space-y-5">
              <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-950 dark:text-amber-100">
                <div className="mb-2 flex items-center gap-2 font-semibold"><AlertTriangle className="h-5 w-5" />NEW / CLEAN ROUTERS ONLY</div>
                <p>SolarNet first reads the router. It stops with <strong>ROUTER IS NOT CLEAN</strong> if it finds production DHCP, VLAN, HotSpot, PPPoE, queues, custom firewall/routing, scripts, previous SolarNet configuration, or an unreadable required area.</p>
              </div>
              <div className="grid gap-3 text-sm md:grid-cols-2">
                <div className="rounded-lg border border-border p-4"><strong className="text-foreground">Included after approval</strong><p className="mt-1 text-muted-foreground">Selected IPoE customer VLAN, DHCP scope, optional isolated portal VLAN, payment-only billing infrastructure, verified backup and audit trail.</p></div>
                <div className="rounded-lg border border-border p-4"><strong className="text-foreground">Never automatic</strong><p className="mt-1 text-muted-foreground">Factory reset, PPPoE changes, bridge-port moves, customer records, customer queues, static leases, firewall/mangle/routing changes, or captive portal on the customer IPoE VLAN.</p></div>
              </div>
              <p className="text-xs text-muted-foreground">This read-only safety scan can take up to two minutes for a router reached through VPN or port-forwarding. It does not change the router.</p>
              <button type="button" onClick={() => void discover()} disabled={busy !== null} className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-50" data-testid="router-provision-discover">
                {busy === 'discover' ? <Loader2 className="h-4 w-4 animate-spin" /> : <ClipboardCheck className="h-4 w-4" />} Run read-only clean-router discovery
              </button>
              {discovery && !discovery.clean && <DiscoveryResult discovery={discovery} onRestart={() => void discover()} busy={busy === 'discover'} />}
            </div>
          )}

          {step === 'config' && discovery && audit && (
            <div className="space-y-5">
              <DiscoveryResult discovery={discovery} onRestart={() => void discover()} busy={busy === 'discover'} />
              <section className="rounded-xl border border-border p-4">
                <div className="mb-4 flex items-center gap-2"><Wifi className="h-5 w-5 text-cyan-600" /><div><h3 className="font-semibold text-foreground">Administrator-selected IPoE network plan</h3><p className="text-sm text-muted-foreground">SolarNet will not guess WAN or change bridge-port membership.</p></div></div>
                <div className="grid gap-4 md:grid-cols-2">
                  <label className="text-sm font-medium text-foreground">Confirmed WAN interface<select className={fieldClass} value={form.wan_interface} onChange={(event) => setForm({ ...form, wan_interface: event.target.value })}><option value="">Select WAN interface</option>{discovery.running_interfaces.map((name) => <option key={name} value={name}>{name}</option>)}</select></label>
                  <label className="text-sm font-medium text-foreground">Customer VLAN parent interface<select className={fieldClass} value={form.customer_parent_interface} onChange={(event) => setForm({ ...form, customer_parent_interface: event.target.value })}><option value="">Select customer parent</option>{discovery.running_interfaces.filter((name) => name !== form.wan_interface).map((name) => <option key={name} value={name}>{name}</option>)}</select></label>
                  <label className="text-sm font-medium text-foreground">Customer VLAN ID<input className={fieldClass} type="number" min="2" max="4094" value={form.customer_vlan_id} onChange={(event) => setForm({ ...form, customer_vlan_id: event.target.value })} /></label>
                  <label className="text-sm font-medium text-foreground">Customer gateway CIDR<input className={fieldClass} value={form.customer_gateway_cidr} onChange={(event) => setForm({ ...form, customer_gateway_cidr: event.target.value })} placeholder="10.100.0.1/24" /></label>
                  <label className="text-sm font-medium text-foreground">DHCP pool range<input className={fieldClass} value={form.customer_dhcp_pool} onChange={(event) => setForm({ ...form, customer_dhcp_pool: event.target.value })} placeholder="10.100.0.10-10.100.0.254" /></label>
                  <label className="text-sm font-medium text-foreground">DNS servers (comma-separated)<input className={fieldClass} value={form.dns_servers} onChange={(event) => setForm({ ...form, dns_servers: event.target.value })} placeholder="1.1.1.1,8.8.8.8" /></label>
                </div>
                <label className="mt-5 flex gap-3 rounded-lg border border-border p-3 text-sm text-foreground"><input type="checkbox" checked={form.enable_captive_portal} onChange={(event) => setForm({ ...form, enable_captive_portal: event.target.checked })} className="mt-0.5 h-4 w-4" /><span><strong>Prepare a separate captive-portal VLAN</strong><br /><span className="text-muted-foreground">Optional. This does not put customer IPoE subscribers behind HotSpot and never creates PPPoE.</span></span></label>
                {form.enable_captive_portal && <div className="mt-4 grid gap-4 rounded-lg border border-cyan-500/30 bg-cyan-500/5 p-4 md:grid-cols-3"><label className="text-sm font-medium text-foreground">Portal VLAN ID<input className={fieldClass} type="number" min="2" max="4094" value={form.portal_vlan_id} onChange={(event) => setForm({ ...form, portal_vlan_id: event.target.value })} /></label><label className="text-sm font-medium text-foreground">Portal gateway CIDR<input className={fieldClass} value={form.portal_gateway_cidr} onChange={(event) => setForm({ ...form, portal_gateway_cidr: event.target.value })} /></label><label className="text-sm font-medium text-foreground">Portal DHCP pool<input className={fieldClass} value={form.portal_dhcp_pool} onChange={(event) => setForm({ ...form, portal_dhcp_pool: event.target.value })} /></label></div>}
              </section>
              <button type="button" onClick={() => void preview()} disabled={busy !== null} className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-50" data-testid="router-provision-preview">{busy === 'preview' && <Loader2 className="h-4 w-4 animate-spin" />} Generate safe plan</button>
            </div>
          )}

          {step === 'preview' && plan && audit && (
            <div className="space-y-5">
              <section className="rounded-xl border border-cyan-500/35 bg-cyan-500/5 p-4"><div className="mb-3 flex items-center gap-2"><ClipboardCheck className="h-5 w-5 text-cyan-600" /><h3 className="font-semibold text-foreground">Reviewed provisioning plan</h3></div><div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3"><PlanValue label="Access" value={plan.access} /><PlanValue label="PPPoE" value={plan.pppoe} /><PlanValue label="WAN" value={plan.wan_interface} /><PlanValue label="Customer VLAN" value={`${plan.customer_vlan_id} on ${plan.customer_parent_interface}`} /><PlanValue label="Gateway" value={plan.customer_gateway_cidr} /><PlanValue label="DHCP pool" value={plan.customer_dhcp_pool} /><PlanValue label="QoS result" value={plan.qos_mode === 'safe_compatible' ? 'Safe QoS compatible' : 'No automatic QoS; FQ-CoDel unavailable'} /><PlanValue label="Captive portal" value={plan.captive_portal.enabled ? `Isolated VLAN ${plan.captive_portal.vlan_id}` : 'Not selected'} /></div><ul className="mt-4 list-disc space-y-1 pl-5 text-sm text-muted-foreground">{plan.planned_changes.map((change) => <li key={change}>{change}</li>)}</ul></section>
              <section className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4"><h3 className="font-semibold text-amber-950 dark:text-amber-100">Final confirmation</h3><p className="mt-1 text-sm text-amber-900 dark:text-amber-200">A fresh router read and verified RouterOS backup run immediately before applying. If verification fails, SolarNet removes only resources created by this plan and tells you to restore the verified backup if rollback cannot be confirmed.</p><label className="mt-4 block text-sm font-medium text-foreground">Type exactly to apply:<span className="mt-2 block select-all rounded bg-background p-2 font-mono text-xs text-muted-foreground">{CONFIRMATION}</span><input className={fieldClass} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} placeholder="Type the confirmation sentence" /></label></section>
              <div className="flex gap-3"><button type="button" onClick={() => setStep('config')} disabled={busy !== null} className="rounded-md border border-input px-4 py-2.5 text-sm font-semibold text-foreground hover:bg-secondary disabled:opacity-50">Back to plan</button><button type="button" onClick={() => void apply()} disabled={busy !== null || confirmation !== CONFIRMATION} className="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50" data-testid="router-provision-apply">{busy === 'apply' && <Loader2 className="h-4 w-4 animate-spin" />} Backup and apply IPoE plan</button></div>
            </div>
          )}

          {step === 'complete' && (
            <div className="space-y-5"><div className="rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-5 text-emerald-950 dark:text-emerald-100"><div className="flex gap-3"><CheckCircle2 className="h-6 w-6 shrink-0" /><div><h3 className="font-semibold">Base infrastructure verification completed</h3><p className="mt-1 text-sm">Connect one IPoE ONU/OLT client and verify it receives DHCP, DNS, internet, billing queue creation, and payment-only suspension behavior. SolarNet does not create a customer or queue during base setup.</p></div></div></div><div className="flex justify-end"><button type="button" onClick={onClose} className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground">Close</button></div></div>
          )}
        </div>
      </div>
    </div>
  );
}

function DiscoveryResult({ discovery, onRestart, busy }: { discovery: RouterProvisioningDiscovery; onRestart: () => void; busy: boolean }) {
  const baseline = discovery.baseline_connectivity;
  return <section className={`rounded-xl border p-4 ${discovery.clean ? 'border-emerald-500/40 bg-emerald-500/5' : 'border-red-500/40 bg-red-500/5'}`}><div className="flex flex-wrap items-start justify-between gap-3"><div><div className="flex items-center gap-2 font-semibold text-foreground">{discovery.clean ? <CheckCircle2 className="h-5 w-5 text-emerald-600" /> : <ShieldAlert className="h-5 w-5 text-red-600" />}{discovery.clean ? 'Router passed the clean-router safety gate' : 'ROUTER IS NOT CLEAN'}</div><p className="mt-1 text-sm text-muted-foreground">{discovery.board_name || 'Unknown board'} · RouterOS {discovery.routeros_version || 'unknown'} · {discovery.running_interfaces.length} running interface(s)</p></div><button type="button" onClick={onRestart} disabled={busy} className="rounded-md border border-input px-3 py-1.5 text-xs font-semibold text-foreground hover:bg-secondary disabled:opacity-50">{busy ? 'Reading…' : 'Run discovery again'}</button></div>{discovery.blockers.length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-red-800 dark:text-red-200">{discovery.blockers.map((blocker) => <li key={blocker}>{blocker}</li>)}</ul>}{baseline && (baseline.masquerade_nat_rules > 0 || baseline.api_input_rules > 0) && <div className="mt-3 rounded-lg border border-cyan-500/30 bg-cyan-500/10 p-3 text-xs text-cyan-950 dark:text-cyan-100">Accepted connectivity baseline: {baseline.masquerade_nat_rules} standard masquerade NAT rule(s) and {baseline.api_input_rules} API input allow rule(s){baseline.api_service_ports.length ? ` (API port ${baseline.api_service_ports.join(', ')})` : ''}. These rules are preserved and never replaced by this setup.</div>}{baseline?.warnings.map((warning) => <div key={warning} className="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-950 dark:text-amber-100">{warning}</div>)}<div className="mt-4 grid grid-cols-2 gap-2 text-xs text-muted-foreground md:grid-cols-4"><div>DHCP servers: {discovery.counts.dhcp_servers ?? 0}</div><div>VLANs: {discovery.counts.vlans ?? 0}</div><div>Queues: {(discovery.counts.simple_queues ?? 0) + (discovery.counts.queue_trees ?? 0)}</div><div>PPPoE: {(discovery.counts.pppoe_servers ?? 0) + (discovery.counts.pppoe_clients ?? 0) + (discovery.counts.ppp_secrets ?? 0)}</div></div></section>;
}

function PlanValue({ label, value }: { label: string; value: string | number }) {
  return <div className="rounded-lg border border-border bg-card/70 p-3"><div className="text-xs uppercase tracking-wide text-muted-foreground">{label}</div><div className="mt-1 break-words font-medium text-foreground">{value}</div></div>;
}
