import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Activity, ClipboardList, Edit3, Loader2, Plus, RefreshCw, Router, ShieldCheck, Trash2, XCircle } from 'lucide-react';
import { api } from '@/services/api';

type OltStatus = 'online' | 'offline' | 'unknown';

interface HsgqVendorHealth {
  platform_version?: string;
  firmware_release?: string;
  software_version?: string;
  model?: string;
  build?: string;
  fan_reading?: string;
  power_source?: string;
}

interface OltInterfaceMetric {
  index: number;
  name: string;
  admin_status: string;
  oper_status: string;
  speed_mbps?: number | null;
  in_octets?: string | null;
  out_octets?: string | null;
  rx_bytes_per_second?: number | null;
  tx_bytes_per_second?: number | null;
  in_errors?: number | null;
  out_errors?: number | null;
  in_discards?: number | null;
  out_discards?: number | null;
}

interface OltInterfaceMonitoring {
  sampled_at: string;
  interface_count: number;
  interfaces: OltInterfaceMetric[];
  truncated?: boolean;
  mode: string;
  relay_router: string;
}

interface OltSnapshot {
  system_description?: string | null;
  system_object_id?: string | null;
  system_uptime?: string | null;
  system_name?: string | null;
  interface_count?: number | null;
  polled_at?: string | null;
  relay_router?: string | null;
  hsgq_vendor_health?: HsgqVendorHealth | null;
  interface_monitoring?: OltInterfaceMonitoring | null;
}

type RouterOption = {
  id: string;
  name: string;
  connection_status: string;
  is_active: boolean;
};

interface OltDevice {
  id: string;
  name: string;
  router_id?: string | null;
  relay_router?: RouterOption | null;
  host: string;
  snmp_port: number;
  snmp_version: '2c';
  has_snmp_community: boolean;
  location?: string | null;
  model?: string | null;
  notes?: string | null;
  is_active: boolean;
  connection_status: OltStatus;
  last_checked_at?: string | null;
  last_snapshot?: OltSnapshot | null;
}

type OltForm = {
  name: string;
  router_id: string;
  host: string;
  snmp_port: number;
  snmp_community: string;
  location: string;
  model: string;
  notes: string;
  is_active: boolean;
};

const emptyForm: OltForm = {
  name: '', router_id: '', host: '', snmp_port: 161, snmp_community: '', location: '', model: '', notes: '', is_active: true,
};

const hsgqHealthLabels: Array<[keyof HsgqVendorHealth, string]> = [
  ['model', 'Reported model'],
  ['firmware_release', 'Reported firmware'],
  ['software_version', 'Reported software'],
  ['build', 'Reported build'],
  ['fan_reading', 'Reported fans'],
  ['power_source', 'Reported power source'],
];

const traffic = (bytesPerSecond?: number | null) => {
  if (bytesPerSecond === null || bytesPerSecond === undefined) return 'First sample';
  const bitsPerSecond = bytesPerSecond * 8;
  if (bitsPerSecond < 1_000) return `${bitsPerSecond.toFixed(0)} bps`;
  if (bitsPerSecond < 1_000_000) return `${(bitsPerSecond / 1_000).toFixed(1)} Kbps`;
  return `${(bitsPerSecond / 1_000_000).toFixed(1)} Mbps`;
};

const statusStyle: Record<OltStatus, string> = {
  online: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
  offline: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
  unknown: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

const errorMessage = (error: any, fallback: string) => error?.response?.data?.message || fallback;

const isHsgqG04R = (device?: OltDevice) => {
  const reportedModel = device?.last_snapshot?.hsgq_vendor_health?.model || '';
  const systemDescription = device?.last_snapshot?.system_description || '';
  const configuredModel = device?.model || '';
  return [reportedModel, systemDescription, configuredModel].some((value) => value.toUpperCase().includes('HSGQ-G04R'));
};

const isPonPort = (port: OltInterfaceMetric) => /^PON\d+$/i.test(port.name);
const isUplinkPort = (port: OltInterfaceMetric) => /^(?:GE|XGE)\d+$/i.test(port.name);
const hasPortErrors = (port: OltInterfaceMetric) => [port.in_errors, port.out_errors, port.in_discards, port.out_discards]
  .some((value) => Number(value || 0) > 0);

export function OltSnmpManager() {
  const [devices, setDevices] = useState<OltDevice[]>([]);
  const [routers, setRouters] = useState<RouterOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testingId, setTestingId] = useState<string | null>(null);
  const [editing, setEditing] = useState<OltDevice | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<OltForm>(emptyForm);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [interfaceOltId, setInterfaceOltId] = useState('');
  const [interfaceSnapshots, setInterfaceSnapshots] = useState<Record<string, OltInterfaceMonitoring>>({});
  const [interfaceLoading, setInterfaceLoading] = useState(false);
  const [interfaceSearch, setInterfaceSearch] = useState('');

  const load = async () => {
    setLoading(true);
    try {
      const [oltResponse, routerResponse] = await Promise.all([
        api.get<{ success: boolean; data: OltDevice[] }>('/olts'),
        api.get<{ success: boolean; data: RouterOption[] }>('/routers'),
      ]);
      setDevices(oltResponse.data.data);
      setRouters(routerResponse.data.data.filter((router) => router.is_active));
      setInterfaceOltId((current) => current && oltResponse.data.data.some((device) => device.id === current) ? current : (oltResponse.data.data[0]?.id || ''));
    } catch (requestError) {
      setError(errorMessage(requestError, 'Could not load OLT devices and available MikroTik relay routers.'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setError('');
    setNotice('');
    setShowForm(true);
  };

  const openEdit = (device: OltDevice) => {
    setEditing(device);
    setForm({
      name: device.name,
      router_id: device.router_id || '',
      host: device.host,
      snmp_port: device.snmp_port,
      snmp_community: '',
      location: device.location || '',
      model: device.model || '',
      notes: device.notes || '',
      is_active: device.is_active,
    });
    setError('');
    setNotice('');
    setShowForm(true);
  };

  const save = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const payload = { ...form, snmp_version: '2c' as const };
      if (editing) {
        await api.put(`/olts/${editing.id}`, payload);
        setNotice('OLT SNMP relay settings updated.');
      } else {
        await api.post('/olts', payload);
        setNotice('OLT saved. Use Test SNMP to confirm its read-only MikroTik relay path.');
      }
      setShowForm(false);
      await load();
    } catch (requestError) {
      setError(errorMessage(requestError, 'Could not save OLT SNMP relay settings.'));
    } finally {
      setSaving(false);
    }
  };

  const test = async (device: OltDevice) => {
    setTestingId(device.id);
    setError('');
    setNotice('');
    try {
      const response = await api.post<{ success: boolean; message: string }>(`/olts/${device.id}/test`);
      setNotice(response.data.message);
      await load();
    } catch (requestError) {
      setError(errorMessage(requestError, 'SNMP relay test failed.'));
      await load();
    } finally {
      setTestingId(null);
    }
  };

  const remove = async (device: OltDevice) => {
    if (!window.confirm(`Remove ${device.name} from SolarNet SNMP monitoring? This does not change the OLT or MikroTik.`)) return;
    setError('');
    setNotice('');
    try {
      await api.delete(`/olts/${device.id}`);
      setNotice(`${device.name} was removed from SolarNet monitoring. The OLT and MikroTik were not changed.`);
      await load();
    } catch (requestError) {
      setError(errorMessage(requestError, 'Could not remove OLT monitoring.'));
    }
  };

  const refreshInterfaces = async () => {
    if (!interfaceOltId) return;
    setInterfaceLoading(true);
    setError('');
    setNotice('');
    try {
      // An OLT interface sample performs several bounded, read-only SNMP walks
      // through the management router. It can legitimately take longer than the
      // application's normal API timeout, especially on an OLT with many ports.
      const response = await api.post<{ success: boolean; message: string; data: OltInterfaceMonitoring }>(
        `/olts/${interfaceOltId}/interfaces/refresh`,
        undefined,
        { timeout: 120_000 },
      );
      setInterfaceSnapshots((current) => ({ ...current, [interfaceOltId]: response.data.data }));
      setNotice(response.data.message);
    } catch (requestError) {
      setError(errorMessage(requestError, 'Could not refresh the read-only OLT interface monitor.'));
    } finally {
      setInterfaceLoading(false);
    }
  };

  const selectedInterfaceDevice = devices.find((device) => device.id === interfaceOltId);
  const selectedInterfaceSnapshot = interfaceOltId
    ? interfaceSnapshots[interfaceOltId] || selectedInterfaceDevice?.last_snapshot?.interface_monitoring || null
    : null;
  const visibleInterfaces = (selectedInterfaceSnapshot?.interfaces || []).filter((item) => `${item.index} ${item.name} ${item.admin_status} ${item.oper_status}`.toLowerCase().includes(interfaceSearch.trim().toLowerCase()));
  const selectedHsgqPorts = (selectedInterfaceSnapshot?.interfaces || []).filter((port) => isPonPort(port) || isUplinkPort(port));
  const selectedPonPorts = selectedHsgqPorts.filter(isPonPort);
  const selectedUplinkPorts = selectedHsgqPorts.filter(isUplinkPort);
  const onlinePonPorts = selectedPonPorts.filter((port) => port.oper_status === 'up').length;
  const onlineUplinkPorts = selectedUplinkPorts.filter((port) => port.oper_status === 'up').length;
  const portsWithErrors = selectedHsgqPorts.filter(hasPortErrors).length;

  return (
    <div className="space-y-6">
      <section className="relative overflow-hidden rounded-3xl border border-cyan-500/25 bg-gradient-to-br from-slate-950 via-slate-900 to-cyan-950 p-6 text-white shadow-xl md:p-8">
        <div className="absolute -right-20 -top-20 h-60 w-60 rounded-full bg-cyan-400/20 blur-3xl" />
        <div className="relative flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
          <div>
            <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-cyan-200"><Activity className="h-4 w-4" /> Read-only SNMP monitoring</div>
            <h2 className="text-2xl font-semibold tracking-tight">OLT Devices</h2>
            <p className="mt-2 max-w-2xl text-sm text-slate-300">Monitor each OLT through the selected MikroTik management router. SolarNet relays only fixed read-only standard SNMP health checks; it never exposes OLT SNMP publicly or sends OLT configuration commands.</p>
          </div>
          <button type="button" onClick={openCreate} className="inline-flex items-center justify-center gap-2 rounded-xl bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-cyan-300"><Plus className="h-4 w-4" /> Add OLT via SNMP</button>
        </div>
      </section>

      <section className="rounded-2xl border border-amber-500/25 bg-amber-500/5 p-4 text-sm text-muted-foreground">
        <div className="flex gap-3"><ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-300" /><div><p className="font-semibold text-foreground">Before adding an OLT</p><p className="mt-1">Enable SNMP v2c with a dedicated <strong>read-only</strong> community, use UDP port 161, and select the MikroTik that can reach the OLT management IP. Permit SNMP only from that management router; SolarNet does not require a public UDP 161 rule. ONU discovery, authorization, and reboot remain disabled until a vendor-specific MIB is reviewed.</p></div></div>
      </section>

      {(error || notice) && <div className={`rounded-xl border p-4 text-sm ${error ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'}`}>{error || notice}</div>}

      {showForm && <section className="rounded-2xl border border-border bg-card p-6 shadow-sm">
        <div className="mb-5 flex items-start justify-between gap-4"><div><h3 className="text-lg font-semibold text-foreground">{editing ? 'Edit OLT SNMP settings' : 'Add OLT via SNMP'}</h3><p className="mt-1 text-sm text-muted-foreground">SolarNet relays fixed, read-only SNMP GETs through the selected MikroTik API connection. The community is encrypted at rest and never displayed again.</p></div><button type="button" onClick={() => setShowForm(false)} className="rounded-lg p-2 text-muted-foreground hover:bg-muted hover:text-foreground"><XCircle className="h-5 w-5" /></button></div>
        <form onSubmit={save} className="grid gap-4 md:grid-cols-2">
          <label className="text-sm font-medium">OLT name *<input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Main OLT" className="mt-1 h-11 w-full rounded-lg border border-input bg-background px-3" /></label>
          <label className="text-sm font-medium">MikroTik relay router *<select required value={form.router_id} onChange={(event) => setForm({ ...form, router_id: event.target.value })} className="mt-1 h-11 w-full rounded-lg border border-input bg-background px-3"><option value="">Select the router that reaches this OLT</option>{routers.map((router) => <option key={router.id} value={router.id}>{router.name} · {router.connection_status}</option>)}</select><span className="mt-1 block text-xs font-normal text-muted-foreground">The router issues the read-only SNMP request inside your management network.</span></label>
          <label className="text-sm font-medium">OLT management IP *<input required inputMode="decimal" value={form.host} onChange={(event) => setForm({ ...form, host: event.target.value })} placeholder="192.168.88.10" className="mt-1 h-11 w-full rounded-lg border border-input bg-background px-3" /></label>
          <label className="text-sm font-medium">SNMP port *<input required type="number" min="1" max="65535" value={form.snmp_port} onChange={(event) => setForm({ ...form, snmp_port: Number(event.target.value) || 161 })} className="mt-1 h-11 w-full rounded-lg border border-input bg-background px-3" /></label>
          <label className="text-sm font-medium">SNMP version<input value="SNMP v2c — read-only" disabled className="mt-1 h-11 w-full rounded-lg border border-input bg-muted px-3 text-muted-foreground" /></label>
          <label className="text-sm font-medium">Read-only community {editing ? <span className="font-normal text-muted-foreground">(leave blank to keep current)</span> : '*'}<input required={!editing} type="password" autoComplete="new-password" value={form.snmp_community} onChange={(event) => setForm({ ...form, snmp_community: event.target.value })} placeholder={editing ? 'Encrypted; unchanged when blank' : 'Read-only community'} className="mt-1 h-11 w-full rounded-lg border border-input bg-background px-3" /></label>
          <label className="text-sm font-medium">Location<input value={form.location} onChange={(event) => setForm({ ...form, location: event.target.value })} placeholder="Main POP" className="mt-1 h-11 w-full rounded-lg border border-input bg-background px-3" /></label>
          <label className="text-sm font-medium">Model <span className="font-normal text-muted-foreground">(optional)</span><input value={form.model} onChange={(event) => setForm({ ...form, model: event.target.value })} placeholder="Vendor / model" className="mt-1 h-11 w-full rounded-lg border border-input bg-background px-3" /></label>
          <label className="flex items-center gap-2 self-end pb-2 text-sm font-medium"><input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} /> Active monitoring</label>
          <label className="text-sm font-medium md:col-span-2">Notes<textarea value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} rows={3} placeholder="PON shelf, management VLAN, or read-only monitoring note" className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2" /></label>
          <div className="flex gap-3 md:col-span-2"><button disabled={saving} type="submit" className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-60">{saving && <Loader2 className="h-4 w-4 animate-spin" />}{editing ? 'Save SNMP settings' : 'Save OLT'}</button><button type="button" onClick={() => setShowForm(false)} className="rounded-lg border px-4 py-2.5 text-sm font-semibold">Cancel</button></div>
        </form>
      </section>}

      <section className="rounded-2xl border border-border bg-card shadow-sm">
        <div className="flex items-center justify-between gap-3 border-b border-border px-5 py-4"><div><h3 className="font-semibold text-foreground">SNMP OLT inventory</h3><p className="mt-1 text-xs text-muted-foreground">Standard system and interface health only, relayed through the selected MikroTik.</p></div><button type="button" onClick={() => void load()} className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh</button></div>
        {loading ? <div className="flex min-h-40 items-center justify-center text-sm text-muted-foreground"><Loader2 className="mr-2 h-4 w-4 animate-spin" />Loading OLT inventory…</div> : devices.length === 0 ? <div className="p-10 text-center"><Router className="mx-auto h-10 w-10 text-muted-foreground" /><p className="mt-3 font-semibold text-foreground">No SNMP OLT devices yet</p><p className="mt-1 text-sm text-muted-foreground">Add an OLT management IP and its MikroTik relay router to begin read-only monitoring.</p></div> : <div className="divide-y divide-border">{devices.map((device) => <article key={device.id} className="p-5"><div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h4 className="font-semibold text-foreground">{device.name}</h4><span className={`rounded-full px-2 py-1 text-[11px] font-bold uppercase ${statusStyle[device.connection_status]}`}>{device.connection_status}</span></div><p className="mt-1 font-mono text-sm text-muted-foreground">{device.host}:{device.snmp_port} · SNMP v2c</p><p className="mt-1 text-xs text-muted-foreground">Relay: {device.relay_router ? `${device.relay_router.name} · ${device.relay_router.connection_status}` : 'Select a MikroTik relay router'} · {device.location || 'Location not set'}{device.model ? ` · ${device.model}` : ''} · last check {device.last_checked_at ? new Date(device.last_checked_at).toLocaleString('en-PH') : 'never'}</p></div><div className="flex flex-wrap gap-2"><button type="button" disabled={testingId === device.id} onClick={() => void test(device)} className="inline-flex items-center gap-2 rounded-lg bg-cyan-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60">{testingId === device.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Activity className="h-4 w-4" />}{testingId === device.id ? 'Testing…' : 'Test SNMP'}</button><button type="button" onClick={() => openEdit(device)} className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold"><Edit3 className="h-4 w-4" />Edit</button><button type="button" onClick={() => void remove(device)} className="inline-flex items-center gap-2 rounded-lg border border-rose-500/30 px-3 py-2 text-sm font-semibold text-rose-600"><Trash2 className="h-4 w-4" />Remove</button></div></div>{device.last_snapshot && <div className="mt-4 grid gap-3 rounded-xl bg-muted/50 p-4 text-sm md:grid-cols-3"><div><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">System name</p><p className="mt-1 break-all font-medium">{device.last_snapshot.system_name || 'Not supplied'}</p></div><div><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Interfaces</p><p className="mt-1 font-medium">{device.last_snapshot.interface_count ?? 'Not supplied'}</p></div><div><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Uptime</p><p className="mt-1 break-all font-medium">{device.last_snapshot.system_uptime || 'Not supplied'}</p></div><div className="md:col-span-3"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">System description</p><p className="mt-1 break-words text-muted-foreground">{device.last_snapshot.system_description || 'Not supplied'}</p></div></div>}</article>)}</div>}
      </section>

      {isHsgqG04R(selectedInterfaceDevice) && <section className="rounded-2xl border border-cyan-500/25 bg-cyan-500/5 p-5 text-sm text-muted-foreground">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"><div><p className="font-semibold text-foreground">HSGQ-G04R port health</p><p className="mt-1 max-w-3xl text-xs">Model-aware summary from the same standard, read-only IF-MIB sample. PON and GE/XGE interfaces are grouped for faster field checks; it does not query ONU records or change OLT configuration.</p></div><span className="rounded-full bg-cyan-500/10 px-2.5 py-1 text-xs font-semibold text-cyan-700 dark:text-cyan-300">Read-only</span></div>
        {!selectedInterfaceSnapshot ? <p className="mt-4 rounded-xl border border-cyan-500/15 bg-background/70 p-4 text-sm">Run <strong className="text-foreground">Refresh ports</strong> to load the verified PON and uplink status summary.</p> : <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><div className="rounded-xl border border-cyan-500/15 bg-background/70 p-4"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">PON ports online</p><p className="mt-2 text-2xl font-semibold text-foreground">{onlinePonPorts}<span className="text-sm text-muted-foreground"> / {selectedPonPorts.length}</span></p></div><div className="rounded-xl border border-cyan-500/15 bg-background/70 p-4"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">GE/XGE uplinks online</p><p className="mt-2 text-2xl font-semibold text-foreground">{onlineUplinkPorts}<span className="text-sm text-muted-foreground"> / {selectedUplinkPorts.length}</span></p></div><div className="rounded-xl border border-cyan-500/15 bg-background/70 p-4"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Reported error/drop ports</p><p className={`mt-2 text-2xl font-semibold ${portsWithErrors ? 'text-amber-600 dark:text-amber-300' : 'text-emerald-600 dark:text-emerald-300'}`}>{portsWithErrors}</p></div><div className="rounded-xl border border-cyan-500/15 bg-background/70 p-4"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Last port sample</p><p className="mt-2 text-sm font-medium text-foreground">{new Date(selectedInterfaceSnapshot.sampled_at).toLocaleString('en-PH')}</p></div></div>}
      </section>}

      <section className="rounded-2xl border border-border bg-card shadow-sm">
        <div className="flex flex-col gap-4 border-b border-border px-5 py-4 lg:flex-row lg:items-center lg:justify-between"><div><h3 className="font-semibold text-foreground">OLT interfaces &amp; PON port monitor</h3><p className="mt-1 text-xs text-muted-foreground">Manual, read-only IF-MIB snapshot of port names, link state, traffic counters, errors, and discards. It does not read ONU, account, or OLT configuration data.</p></div><div className="flex flex-wrap gap-2"><select value={interfaceOltId} onChange={(event) => { setInterfaceOltId(event.target.value); setInterfaceSearch(''); }} className="h-10 min-w-44 rounded-lg border border-input bg-background px-3 text-sm" aria-label="Select OLT for interface monitoring">{devices.map((device) => <option key={device.id} value={device.id}>{device.name}</option>)}</select><button type="button" disabled={!interfaceOltId || interfaceLoading} onClick={() => void refreshInterfaces()} className="inline-flex items-center gap-2 rounded-lg bg-cyan-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60">{interfaceLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}{interfaceLoading ? 'Reading ports…' : 'Refresh ports'}</button></div></div>
        {!selectedInterfaceSnapshot ? <div className="p-8 text-center text-sm text-muted-foreground">Select an OLT and choose <strong className="text-foreground">Refresh ports</strong> to create the first read-only interface snapshot.</div> : <div className="p-5"><div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div className="text-xs text-muted-foreground"><span className="font-semibold text-foreground">{selectedInterfaceSnapshot.interface_count}</span> interfaces · relay {selectedInterfaceSnapshot.relay_router} · updated {new Date(selectedInterfaceSnapshot.sampled_at).toLocaleString('en-PH')}{selectedInterfaceSnapshot.truncated ? ' · display capped at 512 interfaces' : ''}</div><input value={interfaceSearch} onChange={(event) => setInterfaceSearch(event.target.value)} placeholder="Search port, status, or index…" className="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm sm:max-w-xs" /></div><div className="overflow-x-auto rounded-xl border border-border"><table className="min-w-[980px] w-full text-left text-xs"><thead className="bg-muted/60 text-[11px] uppercase tracking-wide text-muted-foreground"><tr><th className="px-3 py-3">ID</th><th className="px-3 py-3">Interface</th><th className="px-3 py-3">Admin</th><th className="px-3 py-3">Link</th><th className="px-3 py-3 text-right">Speed</th><th className="px-3 py-3 text-right">RX</th><th className="px-3 py-3 text-right">TX</th><th className="px-3 py-3 text-right">In err / drop</th><th className="px-3 py-3 text-right">Out err / drop</th></tr></thead><tbody className="divide-y divide-border">{visibleInterfaces.map((item) => <tr key={item.index} className="hover:bg-muted/30"><td className="px-3 py-3 font-mono text-muted-foreground">{item.index}</td><td className="max-w-64 break-all px-3 py-3 font-medium text-foreground">{item.name}</td><td className="px-3 py-3 capitalize text-muted-foreground">{item.admin_status.replaceAll('_', ' ')}</td><td className="px-3 py-3"><span className={`rounded-full px-2 py-1 font-semibold ${item.oper_status === 'up' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : item.oper_status === 'down' || item.oper_status === 'lower_layer_down' ? 'bg-rose-500/10 text-rose-700 dark:text-rose-300' : 'bg-slate-500/10 text-slate-600 dark:text-slate-300'}`}>{item.oper_status.replaceAll('_', ' ')}</span></td><td className="px-3 py-3 text-right text-muted-foreground">{item.speed_mbps ? `${item.speed_mbps.toLocaleString()} Mbps` : '—'}</td><td className="px-3 py-3 text-right font-mono text-cyan-700 dark:text-cyan-300">{traffic(item.rx_bytes_per_second)}</td><td className="px-3 py-3 text-right font-mono text-violet-700 dark:text-violet-300">{traffic(item.tx_bytes_per_second)}</td><td className="px-3 py-3 text-right font-mono text-muted-foreground">{item.in_errors ?? '—'} / {item.in_discards ?? '—'}</td><td className="px-3 py-3 text-right font-mono text-muted-foreground">{item.out_errors ?? '—'} / {item.out_discards ?? '—'}</td></tr>)}{visibleInterfaces.length === 0 && <tr><td colSpan={9} className="px-3 py-8 text-center text-sm text-muted-foreground">No interfaces match this search.</td></tr>}</tbody></table></div><p className="mt-3 text-xs text-muted-foreground">RX and TX rates require two snapshots; the first sample keeps the raw counters only and displays “First sample.”</p></div>}
      </section>

      {devices.some((device) => device.last_snapshot?.system_object_id) && <section className="rounded-2xl border border-violet-500/25 bg-violet-500/5 p-5 text-sm text-muted-foreground"><div className="flex gap-3"><ClipboardList className="mt-0.5 h-5 w-5 shrink-0 text-violet-600 dark:text-violet-300" /><div><p className="font-semibold text-foreground">Vendor MIB discovery is ready for review</p><p className="mt-1">The system object ID below identifies the OLT&apos;s vendor enterprise branch. SolarNet has not walked that branch and cannot infer ONU or optical-power meanings until a vendor MIB or reviewed OID sample is available.</p><div className="mt-3 space-y-1 font-mono text-xs text-foreground">{devices.filter((device) => device.last_snapshot?.system_object_id).map((device) => <p key={device.id}>{device.name}: {device.last_snapshot?.system_object_id}</p>)}</div></div></div></section>}

      {devices.some((device) => device.last_snapshot?.hsgq_vendor_health) && <section className="rounded-2xl border border-cyan-500/25 bg-cyan-500/5 p-5 text-sm text-muted-foreground"><div className="flex gap-3"><Activity className="mt-0.5 h-5 w-5 shrink-0 text-cyan-600 dark:text-cyan-300" /><div className="min-w-0 flex-1"><p className="font-semibold text-foreground">HSGQ reported device health</p><p className="mt-1">Read-only values from a fixed, non-sensitive HSGQ OID allowlist. Fan and power values are shown exactly as reported by the OLT; they are not interpreted as alarms.</p><div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">{devices.filter((device) => device.last_snapshot?.hsgq_vendor_health).map((device) => { const health = device.last_snapshot?.hsgq_vendor_health; return <article key={device.id} className="rounded-xl border border-cyan-500/15 bg-background/70 p-4"><p className="mb-3 font-semibold text-foreground">{device.name}</p><dl className="space-y-2">{hsgqHealthLabels.map(([field, label]) => health?.[field] ? <div key={field} className="flex items-start justify-between gap-3"><dt className="text-xs text-muted-foreground">{label}</dt><dd className="break-all text-right font-mono text-xs text-foreground">{health[field]}</dd></div> : null)}</dl></article>; })}</div></div></div></section>}

      <section className="rounded-2xl border border-border bg-muted/30 p-5 text-sm text-muted-foreground"><div className="flex gap-3"><ClipboardList className="mt-0.5 h-5 w-5 shrink-0 text-primary" /><div><p className="font-semibold text-foreground">Vendor features are intentionally not guessed</p><p className="mt-1">ONT serial discovery, optical levels, authorization, and reboot require the exact vendor model and approved MIB/OID map. SolarNet will not send generic SNMP write commands to a live OLT.</p></div></div></section>
    </div>
  );
}
