import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { FlaskConical, Plus, Radio, RefreshCw, Search, Server, ShieldCheck } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { radiusIpOeService, type RadiusConfigurationStatus, type RadiusNasClient, type RadiusSubscriberRow } from '@/services/radiusIpOeService';

const statusStyle: Record<string, string> = {
  active: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
  grace: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
  suspended: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
  disconnected: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
  pending: 'bg-slate-500/10 text-slate-700 dark:text-slate-300',
  conflict: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
  waiting_for_mac: 'bg-slate-500/10 text-slate-700 dark:text-slate-300',
};

const formatStatus = (value: string): string => value.replaceAll('_', ' ');

export default function RadiusIpOePage() {
  const [configuration, setConfiguration] = useState<RadiusConfigurationStatus | null>(null);
  const [subscribers, setSubscribers] = useState<RadiusSubscriberRow[]>([]);
  const [nasClients, setNasClients] = useState<RadiusNasClient[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [actionId, setActionId] = useState<string | null>(null);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [nasForm, setNasForm] = useState({ name: '', nas_address: '', shortname: '', shared_secret: '', enabled: false, test_mode: true });

  const load = async (term = search): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const [status, rows, nases] = await Promise.all([radiusIpOeService.status(), radiusIpOeService.list(term), radiusIpOeService.listNasClients()]);
      setConfiguration(status);
      setSubscribers(rows);
      setNasClients(nases);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Could not read the RADIUS/IPoE policy workspace.');
    } finally { setLoading(false); }
  };

  useEffect(() => { void load(''); }, []);

  const totals = useMemo(() => ({
    active: subscribers.filter((row) => row.authorization_status === 'active').length,
    grace: subscribers.filter((row) => row.authorization_status === 'grace').length,
    restricted: subscribers.filter((row) => ['suspended', 'disconnected'].includes(row.authorization_status)).length,
    review: subscribers.filter((row) => ['conflict', 'waiting_for_mac', 'pending'].includes(row.authorization_status)).length,
  }), [subscribers]);

  const action = async (customerId: string, kind: 'sync' | 'test'): Promise<void> => {
    setActionId(`${kind}:${customerId}`); setNotice(''); setError('');
    try {
      if (kind === 'sync') await radiusIpOeService.sync(customerId); else await radiusIpOeService.test(customerId);
      setNotice(kind === 'sync'
        ? 'Policy staged from the current SolarNet customer, plan, invoice, and payment data. No network device was changed.'
        : 'Local authorization policy evaluated. No RADIUS packet, DHCP setting, HotSpot action, or MikroTik configuration was changed.');
      await load();
    } catch (err: any) { setError(err?.response?.data?.message || 'The RADIUS/IPoE policy action could not be completed.'); }
    finally { setActionId(null); }
  };

  const stageAll = async (): Promise<void> => {
    if (!window.confirm('Stage the local RADIUS/IPoE policy for every customer? This only writes SolarNet policy and audit records. It does not contact RADIUS or change MikroTik, DHCP, queues, HotSpot, firewall, or customer access.')) return;
    setActionId('stage-all'); setNotice(''); setError('');
    try {
      const result = await radiusIpOeService.stageAll();
      setNotice(`Queued local policy staging for ${result.queued} customer(s). Refresh after the worker processes the queue; no network behavior was changed.`);
    } catch (err: any) { setError(err?.response?.data?.message || 'Could not queue local RADIUS/IPoE policy staging.'); }
    finally { setActionId(null); }
  };

  const createNas = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    if (!window.confirm('Save this exact NAS source and shared secret locally? It will not be sent to FreeRADIUS, opened on the Internet, or applied to MikroTik until Sync NAS is selected after the SQL bridge is enabled.')) return;
    setActionId('create-nas'); setNotice(''); setError('');
    try {
      await radiusIpOeService.createNasClient(nasForm);
      setNasForm({ name: '', nas_address: '', shortname: '', shared_secret: '', enabled: false, test_mode: true });
      setNotice('NAS client saved locally in disabled test mode. It has not been sent to FreeRADIUS or MikroTik.');
      await load();
    } catch (err: any) { setError(err?.response?.data?.message || 'Could not save the FreeRADIUS NAS client.'); }
    finally { setActionId(null); }
  };

  const syncNas = async (nas: RadiusNasClient): Promise<void> => {
    if (!window.confirm(`Sync ${nas.name} into FreeRADIUS SQL? This does not open UDP ports or change MikroTik. FreeRADIUS must be restarted afterwards to load a changed NAS list.`)) return;
    setActionId(`nas:${nas.id}`); setNotice(''); setError('');
    try {
      await radiusIpOeService.syncNasClient(nas.id);
      setNotice('FreeRADIUS NAS SQL row synchronized. UDP remains loopback-bound unless the server is explicitly reconfigured for an isolated test.');
      await load();
    } catch (err: any) { setError(err?.response?.data?.message || 'Could not synchronize the NAS into FreeRADIUS SQL.'); }
    finally { setActionId(null); }
  };

  return <DashboardLayout>
    <div className="mx-auto max-w-[1500px] space-y-5">
      <header className="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between">
        <div className="flex items-start gap-3"><div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-cyan-500 to-blue-700 text-white shadow-lg"><Radio className="h-5 w-5" /></div><div><h1 className="text-2xl font-bold text-foreground">RADIUS &amp; IPoE policy</h1><p className="mt-1 max-w-3xl text-sm text-muted-foreground">Staged subscriber authorization derived from the existing SolarNet billing, customer, service-plan, DHCP, and queue records.</p></div></div>
        <div className="flex flex-wrap gap-2">
          <button type="button" onClick={() => void stageAll()} disabled={loading || actionId !== null} className="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"><Radio className={`h-4 w-4 ${actionId === 'stage-all' ? 'animate-pulse' : ''}`} />Stage all locally</button>
          <button type="button" onClick={() => void load()} disabled={loading || actionId !== null} className="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-50"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh</button>
        </div>
      </header>
      <section className="rounded-xl border border-amber-300/60 bg-amber-50 p-4 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"><div className="flex gap-3"><ShieldCheck className="mt-0.5 h-5 w-5 shrink-0" /><div><p className="font-semibold">Staged only — no customer network behavior changed</p><p className="mt-1 text-sm opacity-90">RADIUS, DHCP RADIUS, HotSpot, walled-garden, CoA, and external RADIUS writes remain off. SolarNet continues to use the existing Simple Queue and billing suspension workflow until a reviewed one-customer rollout is approved.</p></div></div></section>
      {configuration && <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><Metric label="Policy staging" value="Ready" tone="good" /><Metric label="FreeRADIUS service" value={configuration.freeradius_enabled ? 'Prepared' : 'Not deployed'} tone={configuration.freeradius_enabled ? 'good' : 'neutral'} /><Metric label="SQL policy bridge" value={configuration.external_bridge_installed ? 'Enabled' : 'Disabled'} tone={configuration.external_bridge_installed ? 'warn' : 'neutral'} /><Metric label="CoA / disconnect" value="Disabled" tone="neutral" /></section>}
      {(error || notice) && <div className={`rounded-lg border p-3 text-sm ${error ? 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200' : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200'}`}>{error || notice}</div>}
      <section className="rounded-2xl border border-border bg-card p-4 shadow-sm">
        <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between"><div className="flex gap-2"><Server className="mt-0.5 h-5 w-5 text-primary" /><div><h2 className="font-semibold text-foreground">FreeRADIUS NAS approval</h2><p className="mt-0.5 max-w-3xl text-sm text-muted-foreground">Approve only the exact source IP of an isolated test router. The shared secret is encrypted in SolarNet and never displayed again. A disabled NAS cannot be copied into FreeRADIUS.</p></div></div><span className="rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">UDP defaults to loopback</span></div>
        <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
          <div className="overflow-x-auto rounded-xl border border-border"><table className="min-w-[700px] w-full text-left text-sm"><thead className="bg-muted/45 text-xs uppercase tracking-wide text-muted-foreground"><tr><th className="px-3 py-2.5">NAS</th><th className="px-3 py-2.5">Source IP</th><th className="px-3 py-2.5">Mode</th><th className="px-3 py-2.5">FreeRADIUS SQL</th><th className="px-3 py-2.5 text-right">Action</th></tr></thead><tbody className="divide-y divide-border">{nasClients.length === 0 ? <tr><td colSpan={5} className="px-3 py-8 text-center text-muted-foreground">No NAS source is approved. Add one only for an isolated test router.</td></tr> : nasClients.map((nas) => <tr key={nas.id}><td className="px-3 py-3"><p className="font-medium text-foreground">{nas.name}</p><p className="mt-0.5 text-xs text-muted-foreground">{nas.shortname}{nas.router ? ` · ${nas.router.name}` : ''}</p></td><td className="px-3 py-3 font-mono text-xs text-foreground">{nas.nas_address}</td><td className="px-3 py-3"><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${nas.enabled ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-slate-500/10 text-slate-700 dark:text-slate-300'}`}>{nas.enabled ? 'Enabled' : 'Disabled'}</span><p className="mt-1 text-xs text-muted-foreground">{nas.test_mode ? 'Isolated test' : 'Production review'}</p></td><td className="px-3 py-3 text-xs text-muted-foreground">{nas.last_synced_at ? 'Synced; restart required' : 'Not synchronized'}</td><td className="px-3 py-3 text-right"><button type="button" onClick={() => void syncNas(nas)} disabled={!configuration?.sql_sync_enabled || actionId !== null} title={configuration?.sql_sync_enabled ? 'Copy this approved NAS into FreeRADIUS SQL' : 'Enable the reviewed FreeRADIUS SQL bridge first'} className="rounded-md border border-border px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50">{actionId === `nas:${nas.id}` ? 'Syncing...' : 'Sync NAS'}</button></td></tr>)}</tbody></table></div>
          <form onSubmit={(event) => void createNas(event)} className="rounded-xl border border-border bg-muted/20 p-3"><h3 className="text-sm font-semibold text-foreground">Add exact NAS source</h3><div className="mt-3 grid gap-2"><label className="text-xs font-medium text-muted-foreground">Name<input required value={nasForm.name} onChange={(event) => setNasForm({ ...nasForm, name: event.target.value })} placeholder="Isolated IPoE test router" className="mt-1 w-full rounded-md border border-input bg-background px-2.5 py-2 text-sm text-foreground" /></label><label className="text-xs font-medium text-muted-foreground">Router source IP<input required value={nasForm.nas_address} onChange={(event) => setNasForm({ ...nasForm, nas_address: event.target.value })} placeholder="10.10.10.2" inputMode="decimal" className="mt-1 w-full rounded-md border border-input bg-background px-2.5 py-2 text-sm font-mono text-foreground" /></label><label className="text-xs font-medium text-muted-foreground">Short name<input required value={nasForm.shortname} onChange={(event) => setNasForm({ ...nasForm, shortname: event.target.value })} placeholder="ipoe-test" className="mt-1 w-full rounded-md border border-input bg-background px-2.5 py-2 text-sm text-foreground" /></label><label className="text-xs font-medium text-muted-foreground">Unique shared secret<input required value={nasForm.shared_secret} onChange={(event) => setNasForm({ ...nasForm, shared_secret: event.target.value })} type="password" minLength={16} autoComplete="new-password" placeholder="At least 16 characters" className="mt-1 w-full rounded-md border border-input bg-background px-2.5 py-2 text-sm text-foreground" /></label><label className="mt-1 flex items-center gap-2 text-xs text-muted-foreground"><input type="checkbox" checked={nasForm.enabled} onChange={(event) => setNasForm({ ...nasForm, enabled: event.target.checked })} />Enable only after FreeRADIUS loopback test passes</label><p className="rounded-md bg-amber-500/10 px-2.5 py-2 text-xs text-amber-800 dark:text-amber-200">Only isolated test NAS records can be synchronized in this release. Production RADIUS rollout remains blocked.</p><button type="submit" disabled={actionId !== null} className="mt-1 inline-flex items-center justify-center gap-1.5 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"><Plus className="h-4 w-4" />Save NAS locally</button></div></form>
        </div>
      </section>
      <section className="grid grid-cols-2 gap-3 lg:grid-cols-4"><Counter label="Active" value={totals.active} tone="text-emerald-600" /><Counter label="Grace period" value={totals.grace} tone="text-amber-600" /><Counter label="Restricted" value={totals.restricted} tone="text-rose-600" /><Counter label="Needs review" value={totals.review} tone="text-slate-600" /></section>
      <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm"><div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center sm:justify-between"><div><h2 className="font-semibold text-foreground">Subscriber policy staging</h2><p className="mt-0.5 text-sm text-muted-foreground">MAC conflicts are blocked. A valid full MAC is required before DHCP RADIUS can identify a subscriber.</p></div><form onSubmit={(event) => { event.preventDefault(); void load(search); }} className="relative w-full sm:max-w-sm"><Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, account, or MAC" className="w-full rounded-lg border border-input bg-background py-2 pl-9 pr-3 text-sm text-foreground outline-none ring-primary focus:ring-2" /></form></div>
        <div className="overflow-x-auto"><table className="min-w-[1000px] w-full text-left text-sm"><thead className="bg-muted/45 text-xs uppercase tracking-wide text-muted-foreground"><tr><th className="px-4 py-3">Client</th><th className="px-4 py-3">MAC / IP</th><th className="px-4 py-3">Plan / rate reply</th><th className="px-4 py-3">Billing / RADIUS</th><th className="px-4 py-3">NAS</th><th className="px-4 py-3 text-right">Safe actions</th></tr></thead><tbody className="divide-y divide-border">
          {loading ? <tr><td className="px-4 py-10 text-center text-muted-foreground" colSpan={6}>Loading RADIUS/IPoE policy…</td></tr> : subscribers.length === 0 ? <tr><td className="px-4 py-10 text-center text-muted-foreground" colSpan={6}>No staged subscribers yet. Edit or synchronize a customer to create a local policy record.</td></tr> : subscribers.map((row) => <tr key={row.id} className="align-top hover:bg-muted/25"><td className="px-4 py-3"><p className="font-medium text-foreground">{row.customer?.full_name || 'Customer unavailable'}</p><p className="mt-0.5 font-mono text-xs text-muted-foreground">{row.customer?.account_number || '—'}</p></td><td className="px-4 py-3"><p className="font-mono text-xs text-foreground">{row.mac_address || 'MAC required'}</p><p className="mt-1 text-xs text-muted-foreground">{row.ip_address || 'No current IP'}</p></td><td className="px-4 py-3"><p className="text-foreground">{row.customer?.service_plan?.name || 'No plan'}</p><p className="mt-1 font-mono text-xs text-muted-foreground">{row.rate_limit ? `Mikrotik-Rate-Limit ${row.rate_limit}` : 'No normal rate reply'}</p></td><td className="px-4 py-3"><p className="capitalize text-foreground">{row.billing_status || '—'}</p><span className={`mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize ${statusStyle[row.authorization_status] || statusStyle.pending}`}>{formatStatus(row.authorization_status)}</span>{row.mac_conflict && <p className="mt-1 text-xs font-medium text-rose-600">MAC conflict — reassignment required</p>}</td><td className="px-4 py-3"><p className="text-foreground">{row.router?.name || 'No NAS/router'}</p><p className="mt-1 text-xs text-muted-foreground">{row.requires_captive_portal ? 'Restricted portal policy' : 'Normal policy'}</p></td><td className="px-4 py-3"><div className="flex justify-end gap-2"><button type="button" onClick={() => row.customer && void action(row.customer.id, 'sync')} disabled={!row.customer || actionId !== null} className="inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${actionId === `sync:${row.customer?.id}` ? 'animate-spin' : ''}`} />Sync</button><button type="button" onClick={() => row.customer && void action(row.customer.id, 'test')} disabled={!row.customer || actionId !== null} className="inline-flex items-center gap-1.5 rounded-md bg-primary px-2.5 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"><FlaskConical className="h-3.5 w-3.5" />Test policy</button></div></td></tr>)}
        </tbody></table></div></section>
    </div>
  </DashboardLayout>;
}

function Metric({ label, value, tone }: { label: string; value: string; tone: 'good' | 'warn' | 'neutral' }) {
  const colors = tone === 'good' ? 'text-emerald-700 dark:text-emerald-300' : tone === 'warn' ? 'text-amber-700 dark:text-amber-300' : 'text-foreground';
  return <div className="rounded-xl border border-border bg-card p-4"><p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p><p className={`mt-2 text-lg font-semibold ${colors}`}>{value}</p></div>;
}

function Counter({ label, value, tone }: { label: string; value: number; tone: string }) {
  return <div className="rounded-xl border border-border bg-card p-4"><p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p><p className={`mt-1 text-2xl font-bold ${tone}`}>{value}</p></div>;
}
