import { useEffect, useMemo, useState } from 'react';
import { MapPinned, RefreshCw, Users } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';

type Person = { id: string; name: string; email: string };
type Customer = { id: string; full_name: string; account_number: string; address?: string; gps_coordinates?: unknown; status: string };
type Assignment = { id: string; territory_label?: string; collector: Person; customer: Customer };
type Stop = { status: string; location_source: string; collectible_balance: number; customer: Customer; visits?: { requires_review?: boolean }[] };
type Plan = { id: string; collector: Person; stops: Stop[] };
type Data = { collectors: Person[]; assignments: Assignment[]; plans: Plan[]; unassigned_customers: number; unassigned_customer_records: Customer[] };
const money = (value: number) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;

export default function CollectionMonitoringPage() {
  const [data, setData] = useState<Data | null>(null);
  const [collector, setCollector] = useState('');
  const [territory, setTerritory] = useState('');
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const load = async () => { const response = await api.get('/collection-monitoring'); setData(response.data); };
  useEffect(() => { void load(); }, []);
  const candidates = useMemo(() => (data?.unassigned_customer_records || []).filter((item) => `${item.full_name} ${item.account_number} ${item.address || ''}`.toLowerCase().includes(search.toLowerCase())).slice(0, 100), [data, search]);
  const assign = async () => { if (!collector || !selected.length) return; setBusy(true); try { await api.post('/collection-monitoring/assignments', { collector_id: collector, customer_ids: selected, territory_label: territory || undefined }); setSelected([]); await load(); } finally { setBusy(false); } };

  return <DashboardLayout><main className="space-y-6 p-4 md:p-6"><header className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="flex items-center gap-2 text-2xl font-bold"><MapPinned className="text-primary" />Collection monitoring</h1><p className="text-sm text-muted-foreground">Assign customers, monitor today’s plans, and review address-only or GPS-verified stops.</p></div><button onClick={() => void load()} className="rounded-lg border px-3 py-2 text-sm"><RefreshCw className="mr-1 inline h-4 w-4" />Refresh</button></header>
    <section className="grid gap-3 sm:grid-cols-3"><div className="rounded-xl border bg-card p-4"><p className="text-xs text-muted-foreground">Collectors</p><p className="text-2xl font-bold">{data?.collectors.length || 0}</p></div><div className="rounded-xl border bg-card p-4"><p className="text-xs text-muted-foreground">Active assignments</p><p className="text-2xl font-bold">{data?.assignments.length || 0}</p></div><div className="rounded-xl border bg-card p-4"><p className="text-xs text-muted-foreground">Unassigned customers</p><p className="text-2xl font-bold">{data?.unassigned_customers || 0}</p></div></section>
    <section className="rounded-2xl border bg-card p-5"><h2 className="flex items-center gap-2 font-bold"><Users className="h-5 w-5" />Assign customers</h2><div className="mt-4 grid gap-3 md:grid-cols-3"><select value={collector} onChange={(e)=>setCollector(e.target.value)} className="rounded-lg border bg-background p-2"><option value="">Select collector</option>{data?.collectors.map((item)=><option key={item.id} value={item.id}>{item.name}</option>)}</select><input value={territory} onChange={(e)=>setTerritory(e.target.value)} placeholder="Territory / barangay label" className="rounded-lg border bg-background p-2"/><input value={search} onChange={(e)=>setSearch(e.target.value)} placeholder="Search unassigned customer or address" className="rounded-lg border bg-background p-2"/></div><div className="mt-3 max-h-72 overflow-auto rounded-xl border">{candidates.map((item)=><label key={item.id} className="flex items-start gap-3 border-b p-3 text-sm"><input type="checkbox" checked={selected.includes(item.id)} onChange={(e)=>setSelected(current=>e.target.checked?[...current,item.id]:current.filter(id=>id!==item.id))}/><span><b>{item.full_name}</b> · {item.account_number}<small className="block text-muted-foreground">{item.address || 'Address not recorded'} · {item.gps_coordinates ? 'GPS available' : 'Address only'}</small></span></label>)}{!candidates.length&&<p className="p-6 text-center text-sm text-muted-foreground">No unassigned customers match.</p>}</div><button disabled={busy||!collector||!selected.length} onClick={()=>void assign()} className="mt-3 rounded-lg bg-primary px-4 py-2 font-semibold text-primary-foreground disabled:opacity-50">Assign {selected.length} selected customer{selected.length===1?'':'s'}</button></section>
    <section className="grid gap-4 lg:grid-cols-2">{data?.plans.map((plan)=><article key={plan.id} className="rounded-2xl border bg-card p-5"><div className="flex justify-between"><h2 className="font-bold">{plan.collector.name}</h2><span className="text-sm text-muted-foreground">{plan.stops.filter(s=>s.status==='completed').length}/{plan.stops.length} completed</span></div><div className="mt-3 grid grid-cols-3 gap-2 text-center text-xs"><div className="rounded-lg bg-muted p-2"><b className="block text-base">{plan.stops.filter(s=>s.location_source==='verified_gps').length}</b>GPS</div><div className="rounded-lg bg-muted p-2"><b className="block text-base">{plan.stops.filter(s=>s.location_source==='address_only').length}</b>Address only</div><div className="rounded-lg bg-muted p-2"><b className="block text-base">{money(plan.stops.reduce((sum,s)=>sum+Number(s.collectible_balance),0))}</b>Target</div></div></article>)}{data&&!data.plans.length&&<p className="rounded-2xl border bg-card p-8 text-center text-muted-foreground">No collector has generated a plan today.</p>}</section>
  </main></DashboardLayout>;
}
