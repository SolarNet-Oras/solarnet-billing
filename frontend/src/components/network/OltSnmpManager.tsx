import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Activity, ClipboardList, Edit3, Loader2, Plus, RefreshCw, Router, ShieldCheck, Trash2, XCircle } from 'lucide-react';
import { api } from '@/services/api';

type OltStatus = 'online' | 'offline' | 'unknown';

interface OltSnapshot {
  system_description?: string | null;
  system_object_id?: string | null;
  system_uptime?: string | null;
  system_name?: string | null;
  interface_count?: number | null;
  polled_at?: string | null;
}

interface OltDevice {
  id: string;
  name: string;
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
  host: string;
  snmp_port: number;
  snmp_community: string;
  location: string;
  model: string;
  notes: string;
  is_active: boolean;
};

const emptyForm: OltForm = {
  name: '', host: '', snmp_port: 161, snmp_community: '', location: '', model: '', notes: '', is_active: true,
};

const statusStyle: Record<OltStatus, string> = {
  online: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
  offline: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
  unknown: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

const errorMessage = (error: any, fallback: string) => error?.response?.data?.message || fallback;

export function OltSnmpManager() {
  const [devices, setDevices] = useState<OltDevice[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testingId, setTestingId] = useState<string | null>(null);
  const [editing, setEditing] = useState<OltDevice | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<OltForm>(emptyForm);
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');

  const load = async () => {
    setLoading(true);
    try {
      const response = await api.get<{ success: boolean; data: OltDevice[] }>('/olts');
      setDevices(response.data.data);
    } catch (requestError) {
      setError(errorMessage(requestError, 'Could not load OLT devices.'));
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
        setNotice('OLT SNMP settings updated.');
      } else {
        await api.post('/olts', payload);
        setNotice('OLT saved. Use Test SNMP to confirm its read-only access.');
      }
      setShowForm(false);
      await load();
    } catch (requestError) {
      setError(errorMessage(requestError, 'Could not save OLT SNMP settings.'));
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
      setError(errorMessage(requestError, 'SNMP test failed.'));
      await load();
    } finally {
      setTestingId(null);
    }
  };

  const remove = async (device: OltDevice) => {
    if (!window.confirm(`Remove ${device.name} from SolarNet SNMP monitoring? This does not change the OLT.`)) return;
    setError('');
    setNotice('');
    try {
      await api.delete(`/olts/${device.id}`);
      setNotice(`${device.name} was removed from SolarNet monitoring. The OLT was not changed.`);
      await load();
    } catch (requestError) {
      setError(errorMessage(requestError, 'Could not remove OLT monitoring.'));
    }
  };

  return (
    <div className="space-y-6">
      <section className="relative overflow-hidden rounded-3xl border border-cyan-500/25 bg-gradient-to-br from-slate-950 via-slate-900 to-cyan-950 p-6 text-white shadow-xl md:p-8">
        <div className="absolute -right-20 -top-20 h-60 w-60 rounded-full bg-cyan-400/20 blur-3xl" />
        <div className="relative flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
          <div>
            <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-cyan-200"><Activity className="h-4 w-4" /> Read-only SNMP monitoring</div>
            <h2 className="text-2xl font-semibold tracking-tight">OLT Devices</h2>
            <p className="mt-2 max-w-2xl text-sm text-slate-300">Monitor each OLT directly through its management IP. SolarNet reads standard health information only and never sends cloud credentials or OLT configuration commands.</p>
          </div>
          <button type="button" onClick={openCreate} className="inline-flex items-center justify-center gap-2 rounded-xl bg-cyan-400 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-cyan-300"><Plus className="h-4 w-4" /> Add OLT via SNMP</button>
        </div>
      </section>

      <section className="rounded-2xl border border-amber-500/25 bg-amber-500/5 p-4 text-sm text-muted-foreground">
        <div className="flex gap-3"><ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-300" /><div><p className="font-semibold text-foreground">Before adding an OLT</p><p className="mt-1">Enable SNMP v2c with a dedicated <strong>read-only</strong> community, use UDP port 161, and allow only the SolarNet VPS or management VPN address in the OLT firewall. ONU discovery, authorization, and reboot remain disabled until a vendor-specific MIB is reviewed.</p></div></div>
      </section>

      {(error || notice) && <div className={`rounded-xl border p-4 text-sm ${error ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'}`}>{error || notice}</div>}

      {showForm && <section className="rounded-2xl border border-border bg-card p-6 shadow-sm">
        <div className="mb-5 flex items-start justify-between gap-4"><div><h3 className="text-lg font-semibold text-foreground">{editing ? 'Edit OLT SNMP settings' : 'Add OLT via SNMP'}</h3><p className="mt-1 text-sm text-muted-foreground">The community is encrypted at rest and never displayed again.</p></div><button type="button" onClick={() => setShowForm(false)} className="rounded-lg p-2 text-muted-foreground hover:bg-muted hover:text-foreground"><XCircle className="h-5 w-5" /></button></div>
        <form onSubmit={save} className="grid gap-4 md:grid-cols-2">
          <label className="text-sm font-medium">OLT name *<input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Main OLT" className="mt-1 h-11 w-full rounded-lg border border-input bg-background px-3" /></label>
          <label className="text-sm font-medium">Management host / IP *<input required value={form.host} onChange={(event) => setForm({ ...form, host: event.target.value })} placeholder="10.0.0.10" className="mt-1 h-11 w-full rounded-lg border border-input bg-background px-3" /></label>
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
        <div className="flex items-center justify-between gap-3 border-b border-border px-5 py-4"><div><h3 className="font-semibold text-foreground">SNMP OLT inventory</h3><p className="mt-1 text-xs text-muted-foreground">Standard system and interface health only.</p></div><button type="button" onClick={() => void load()} className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh</button></div>
        {loading ? <div className="flex min-h-40 items-center justify-center text-sm text-muted-foreground"><Loader2 className="mr-2 h-4 w-4 animate-spin" />Loading OLT inventory…</div> : devices.length === 0 ? <div className="p-10 text-center"><Router className="mx-auto h-10 w-10 text-muted-foreground" /><p className="mt-3 font-semibold text-foreground">No SNMP OLT devices yet</p><p className="mt-1 text-sm text-muted-foreground">Add an OLT management IP to begin read-only monitoring.</p></div> : <div className="divide-y divide-border">{devices.map((device) => <article key={device.id} className="p-5"><div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h4 className="font-semibold text-foreground">{device.name}</h4><span className={`rounded-full px-2 py-1 text-[11px] font-bold uppercase ${statusStyle[device.connection_status]}`}>{device.connection_status}</span></div><p className="mt-1 font-mono text-sm text-muted-foreground">{device.host}:{device.snmp_port} · SNMP v2c</p><p className="mt-1 text-xs text-muted-foreground">{device.location || 'Location not set'}{device.model ? ` · ${device.model}` : ''} · last check {device.last_checked_at ? new Date(device.last_checked_at).toLocaleString('en-PH') : 'never'}</p></div><div className="flex flex-wrap gap-2"><button type="button" disabled={testingId === device.id} onClick={() => void test(device)} className="inline-flex items-center gap-2 rounded-lg bg-cyan-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60">{testingId === device.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Activity className="h-4 w-4" />}{testingId === device.id ? 'Testing…' : 'Test SNMP'}</button><button type="button" onClick={() => openEdit(device)} className="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold"><Edit3 className="h-4 w-4" />Edit</button><button type="button" onClick={() => void remove(device)} className="inline-flex items-center gap-2 rounded-lg border border-rose-500/30 px-3 py-2 text-sm font-semibold text-rose-600"><Trash2 className="h-4 w-4" />Remove</button></div></div>{device.last_snapshot && <div className="mt-4 grid gap-3 rounded-xl bg-muted/50 p-4 text-sm md:grid-cols-3"><div><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">System name</p><p className="mt-1 break-all font-medium">{device.last_snapshot.system_name || 'Not supplied'}</p></div><div><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Interfaces</p><p className="mt-1 font-medium">{device.last_snapshot.interface_count ?? 'Not supplied'}</p></div><div><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Uptime</p><p className="mt-1 break-all font-medium">{device.last_snapshot.system_uptime || 'Not supplied'}</p></div><div className="md:col-span-3"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">System description</p><p className="mt-1 break-words text-muted-foreground">{device.last_snapshot.system_description || 'Not supplied'}</p></div></div>}</article>)}</div>}
      </section>

      <section className="rounded-2xl border border-border bg-muted/30 p-5 text-sm text-muted-foreground"><div className="flex gap-3"><ClipboardList className="mt-0.5 h-5 w-5 shrink-0 text-primary" /><div><p className="font-semibold text-foreground">Vendor features are intentionally not guessed</p><p className="mt-1">ONT serial discovery, optical levels, authorization, and reboot require the exact vendor model and approved MIB/OID map. SolarNet will not send generic SNMP write commands to a live OLT.</p></div></div></section>
    </div>
  );
}
