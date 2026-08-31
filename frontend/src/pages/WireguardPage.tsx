import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Activity, Cable, CheckCircle2, Clipboard, KeyRound, Plus, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { wireguardService, type WireguardPeer, type WireguardPeerInput, type WireguardRouter } from '@/services/wireguardService';

const blank: WireguardPeerInput = {
  router_id: '', name: '', interface_name: 'wg-solarnet', router_public_key: '', server_public_key: '',
  server_endpoint: '', server_port: 51820, server_tunnel_address: '10.77.0.1/30',
  peer_tunnel_address: '10.77.0.2/32', router_listen_port: 13231, persistent_keepalive: 25, enabled: true,
};

const messageOf = (error: unknown): string => axios.isAxiosError(error)
  ? String(error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || error.message)
  : 'The WireGuard request failed.';
const bytes = (value: number): string => value >= 1_048_576 ? `${(value / 1_048_576).toFixed(1)} MB` : value >= 1024 ? `${(value / 1024).toFixed(1)} KB` : `${value || 0} B`;
const inputClass = 'w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20';

export default function WireguardPage(): JSX.Element {
  const [peers, setPeers] = useState<WireguardPeer[]>([]);
  const [routers, setRouters] = useState<WireguardRouter[]>([]);
  const [form, setForm] = useState<WireguardPeerInput>(blank);
  const [showForm, setShowForm] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState('');
  const [scripts, setScripts] = useState<{ mikrotik: string; vpsPeer: string; firewall: string } | null>(null);

  const load = async (): Promise<void> => {
    setBusy('load');
    try { const data = await wireguardService.index(); setPeers(data.peers); setRouters(data.routers); }
    catch (error) { setNotice(messageOf(error)); } finally { setBusy(null); }
  };
  useEffect(() => { void load(); }, []);

  const save = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault(); setBusy('save');
    try { await wireguardService.create(form); setForm(blank); setShowForm(false); setNotice('Peer saved. No network configuration was changed.'); await load(); }
    catch (error) { setNotice(messageOf(error)); } finally { setBusy(null); }
  };
  const act = async (id: string, action: 'inspect' | 'test' | 'scripts'): Promise<void> => {
    setBusy(`${action}-${id}`);
    try {
      if (action === 'scripts') { setScripts(await wireguardService.scripts(id)); setNotice('Scripts generated for review. Nothing was applied.'); }
      else { const result = action === 'inspect' ? await wireguardService.inspect(id) : await wireguardService.test(id); setNotice(result.message); await load(); }
    } catch (error) { setNotice(messageOf(error)); } finally { setBusy(null); }
  };

  return <DashboardLayout headerTitle="WireGuard" headerSubtitle="Super Administrator secure tunnel workspace">
    <div className="space-y-5">
      <section className="overflow-hidden rounded-2xl border border-cyan-500/25 bg-gradient-to-br from-slate-950 via-slate-900 to-cyan-950 p-5 text-slate-100 shadow-xl">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div><div className="flex items-center gap-2 text-cyan-300"><ShieldCheck className="h-5 w-5"/><span className="text-xs font-bold uppercase tracking-[.2em]">Public-key control plane</span></div><h1 className="mt-2 text-2xl font-bold">WireGuard peers & live handshakes</h1><p className="mt-2 max-w-3xl text-sm text-slate-300">Exchange public keys, inspect RouterOS handshake counters, test the private tunnel, and generate reviewable MikroTik/VPS/firewall scripts. SolarNet never stores a private key and never changes the VPS or router from this screen.</p></div>
          <div className="flex gap-2"><button onClick={() => void load()} className="rounded-lg border border-slate-700 px-3 py-2 text-sm hover:bg-slate-800"><RefreshCw className="mr-2 inline h-4 w-4"/>Refresh</button><button onClick={() => setShowForm(v => !v)} className="rounded-lg bg-cyan-500 px-3 py-2 text-sm font-semibold text-slate-950"><Plus className="mr-2 inline h-4 w-4"/>Add peer</button></div>
        </div>
      </section>

      {notice && <div className="rounded-xl border border-border bg-card px-4 py-3 text-sm text-foreground">{notice}</div>}

      {showForm && <form onSubmit={save} className="rounded-2xl border border-border bg-card p-5 shadow-sm">
        <h2 className="text-lg font-semibold">Create peer profile</h2><p className="mb-4 text-sm text-muted-foreground">Paste public keys only. The selected router remains unchanged until you separately review and run a generated script.</p>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <Field label="Saved MikroTik"><select required value={form.router_id} onChange={e => setForm({...form, router_id:e.target.value})} className={inputClass}><option value="">Select router</option>{routers.map(r=><option key={r.id} value={r.id}>{r.name} · {r.connection_status}</option>)}</select></Field>
          <Field label="Peer name"><input required value={form.name} onChange={e=>setForm({...form,name:e.target.value})} className={inputClass} placeholder="Testing WireGuard"/></Field>
          <Field label="Router interface"><input required value={form.interface_name} onChange={e=>setForm({...form,interface_name:e.target.value})} className={inputClass}/></Field>
          <Field label="MikroTik public key"><input required value={form.router_public_key} onChange={e=>setForm({...form,router_public_key:e.target.value.trim()})} className={`${inputClass} font-mono text-xs`} placeholder="44-character public key"/></Field>
          <Field label="VPS public key"><input required value={form.server_public_key} onChange={e=>setForm({...form,server_public_key:e.target.value.trim()})} className={`${inputClass} font-mono text-xs`} placeholder="44-character public key"/></Field>
          <Field label="VPS endpoint"><input required value={form.server_endpoint} onChange={e=>setForm({...form,server_endpoint:e.target.value.trim()})} className={inputClass} placeholder="VPS IP or hostname"/></Field>
          <Field label="VPS UDP port"><input type="number" value={form.server_port} onChange={e=>setForm({...form,server_port:+e.target.value})} className={inputClass}/></Field>
          <Field label="VPS tunnel CIDR"><input value={form.server_tunnel_address} onChange={e=>setForm({...form,server_tunnel_address:e.target.value})} className={inputClass}/></Field>
          <Field label="MikroTik tunnel CIDR"><input value={form.peer_tunnel_address} onChange={e=>setForm({...form,peer_tunnel_address:e.target.value})} className={inputClass}/></Field>
          <Field label="MikroTik listen port"><input type="number" value={form.router_listen_port} onChange={e=>setForm({...form,router_listen_port:+e.target.value})} className={inputClass}/></Field>
          <Field label="Keepalive seconds"><input type="number" value={form.persistent_keepalive} onChange={e=>setForm({...form,persistent_keepalive:+e.target.value})} className={inputClass}/></Field>
        </div><div className="mt-5 flex gap-2"><button disabled={busy==='save'} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground">Save public peer</button><button type="button" onClick={()=>setShowForm(false)} className="rounded-lg border border-border px-4 py-2 text-sm">Cancel</button></div>
      </form>}

      <section className="grid gap-4 lg:grid-cols-2">
        {peers.map(peer => <article key={peer.id} className="rounded-2xl border border-border bg-card p-5 shadow-sm">
          <div className="flex items-start justify-between gap-3"><div><p className="text-xs font-semibold uppercase tracking-wider text-cyan-600">{peer.router.name}</p><h2 className="text-lg font-bold">{peer.name}</h2><p className="text-sm text-muted-foreground">{peer.interface_name} · {peer.peer_tunnel_address} ⇄ {peer.server_tunnel_address}</p></div><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${peer.last_test_status==='connected'||peer.last_test_status==='handshake_seen'?'bg-emerald-500/15 text-emerald-600':'bg-amber-500/15 text-amber-600'}`}>{peer.last_test_status || 'not checked'}</span></div>
          <div className="mt-4 grid grid-cols-2 gap-3 text-sm"><div className="rounded-xl bg-secondary p-3"><span className="text-muted-foreground">Received</span><p className="font-semibold">{bytes(peer.rx_bytes)}</p></div><div className="rounded-xl bg-secondary p-3"><span className="text-muted-foreground">Sent</span><p className="font-semibold">{bytes(peer.tx_bytes)}</p></div></div>
          <div className="mt-4 flex flex-wrap gap-2"><button onClick={()=>void act(peer.id,'inspect')} className="rounded-lg border border-border px-3 py-2 text-sm"><Activity className="mr-1.5 inline h-4 w-4"/>Inspect handshake</button><button onClick={()=>void act(peer.id,'test')} className="rounded-lg border border-border px-3 py-2 text-sm"><Cable className="mr-1.5 inline h-4 w-4"/>Test tunnel</button><button onClick={()=>void act(peer.id,'scripts')} className="rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground"><KeyRound className="mr-1.5 inline h-4 w-4"/>Generate scripts</button><button onClick={async()=>{if(confirm('Delete only this SolarNet inventory record? Router/VPS configuration will remain.')){await wireguardService.remove(peer.id);void load();}}} className="rounded-lg border border-red-500/30 px-3 py-2 text-sm text-red-600"><Trash2 className="h-4 w-4"/></button></div>
        </article>)}
        {!busy && peers.length===0 && <div className="lg:col-span-2 rounded-2xl border border-dashed border-border p-10 text-center text-muted-foreground">No WireGuard peers saved yet. Add the first peer using public keys from the VPS and MikroTik.</div>}
      </section>

      {scripts && <section className="rounded-2xl border border-border bg-card p-5"><div className="mb-4 flex items-center gap-2"><CheckCircle2 className="h-5 w-5 text-emerald-600"/><h2 className="font-semibold">Review-only connection scripts</h2></div><div className="grid gap-4 xl:grid-cols-3">{([['MikroTik',scripts.mikrotik],['VPS peer block',scripts.vpsPeer],['Firewall',scripts.firewall]] as const).map(([title,code])=><div key={title} className="min-w-0"><div className="mb-2 flex justify-between"><h3 className="text-sm font-semibold">{title}</h3><button onClick={()=>void navigator.clipboard.writeText(code)} className="text-xs text-primary"><Clipboard className="mr-1 inline h-3.5 w-3.5"/>Copy</button></div><pre className="max-h-80 overflow-auto whitespace-pre-wrap rounded-xl bg-slate-950 p-3 text-xs text-slate-100">{code}</pre></div>)}</div></section>}
    </div>
  </DashboardLayout>;
}

function Field({label,children}:{label:string;children:React.ReactNode}): JSX.Element { return <label className="space-y-1.5 text-sm font-medium"><span>{label}</span>{children}</label>; }
