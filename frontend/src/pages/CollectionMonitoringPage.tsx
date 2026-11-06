import { useEffect, useMemo, useState } from 'react';
import { Camera, MapPinned, RefreshCw, X } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';

type Person = { id: string; name: string; email: string };
type Customer = { id: string; full_name: string; account_number: string; address?: string; gps_coordinates?: unknown; status: string };
type Assignment = { id: string; territory_label?: string; collector: Person; customer: Customer };
type Visit = { id: string; status: string; outcome?: string | null; remarks?: string | null; completed_at?: string | null; has_photo?: boolean };
type Stop = { id?: string; status: string; location_source: string; collectible_balance: number; customer?: Customer | null; visits?: Visit[] };
type Plan = { id: string; plan_date: string; status: string; collector?: Person | null; stops: Stop[] };
type Territory = { id: string; name: string; address_pattern?: string; is_fallback: boolean; is_active: boolean; priority: number; max_daily_visits: number; collector?: Person | null };
type PlannerRun = { id: string; run_date: string; status: string; dry_run: boolean; eligible_customers: number; new_assignments: number; unassigned_exceptions: number; plans_generated: number; scheduled_visits: number; unscheduled_visits: number };
type Automation = { environment_enabled: boolean; application_enabled: boolean; active: boolean; daily_at: string; timezone: string; algorithm_version: string };
type Coverage = { target_count: number; scheduled: number; unscheduled: number; completed: number; failed: number; total_collectible: number };
type RegistrationEvidence = { id: string; status: string; submitted_email?: string | null; submitted_phone?: string | null; verified_mac_address?: string | null; created_at: string; collector?: Person | null; customer?: (Customer & { email?: string | null; contact_number?: string | null; mac_address?: string | null }) | null };
type Data = { collectors: Person[]; assignments: Assignment[]; plans: Plan[]; unassigned_customers: number; unassigned_customer_records: Customer[]; customers_missing_coordinates: number; registration_evidence: RegistrationEvidence[]; territories: Territory[]; planner_runs: PlannerRun[]; automation: Automation; coverage_report: Coverage; territory_name_options: string[]; territory_area_options: string[] };
const money = (value: number) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;

export default function CollectionMonitoringPage() {
  const [data, setData] = useState<Data | null>(null);
  const [busy, setBusy] = useState(false);
  const [territoryCollector, setTerritoryCollector] = useState('');
  const [territoryName, setTerritoryName] = useState('');
  const [territoryAreas, setTerritoryAreas] = useState<string[]>([]);
  const [overrideCollector, setOverrideCollector] = useState('');
  const [overrideLabel, setOverrideLabel] = useState('');
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<string[]>([]);
  const [macInputs, setMacInputs] = useState<Record<string,string>>({});
  const [quotaInputs, setQuotaInputs] = useState<Record<string,string>>({});
  const [loadError, setLoadError] = useState('');
  const [proofPreview, setProofPreview] = useState<{url:string; title:string} | null>(null);
  const [proofLoading, setProofLoading] = useState<{id:string; percent:number|null} | null>(null);
  const load = async () => {
    setLoadError('');
    try {
      const response = await api.get('/collection-monitoring');
      const payload = response.data || {};
      setData({
        ...payload,
        collectors: payload.collectors || [],
        assignments: payload.assignments || [],
        plans: payload.plans || [],
        unassigned_customer_records: payload.unassigned_customer_records || [],
        registration_evidence: payload.registration_evidence || [],
        territories: payload.territories || [],
        territory_name_options: payload.territory_name_options || [],
        territory_area_options: payload.territory_area_options || [],
        planner_runs: payload.planner_runs || [],
        automation: payload.automation || {
          environment_enabled: false,
          application_enabled: false,
          active: false,
          daily_at: 'not configured',
          timezone: 'Asia/Manila',
          algorithm_version: 'version unavailable',
        },
        coverage_report: payload.coverage_report || {
          target_count: 0,
          scheduled: 0,
          unscheduled: 0,
          completed: 0,
          failed: 0,
          total_collectible: 0,
        },
      });
      setQuotaInputs(Object.fromEntries((payload.territories || [])
        .filter((territory: Territory) => Boolean(territory?.collector?.id))
        .map((territory: Territory) => [territory.collector!.id,String(territory.max_daily_visits)])));
    } catch (error: any) {
      setLoadError(error.response?.data?.message || 'Collection monitoring could not be loaded. Refresh after the backend update is available.');
    }
  };
  useEffect(() => { void load(); }, []);
  const candidates = useMemo(() => (data?.unassigned_customer_records || []).filter(item => `${item.full_name} ${item.account_number} ${item.address || ''}`.toLowerCase().includes(search.toLowerCase())).slice(0, 100), [data, search]);
  const saveTerritory = async () => { const fallback=territoryName==='Fallback territory'; if (!territoryCollector || !territoryName || (!fallback && !territoryAreas.length)) return; setBusy(true); try { await api.post('/collection-monitoring/territories', { collector_id: territoryCollector, name: territoryName, areas: fallback ? [] : territoryAreas, is_fallback: fallback, priority: 100, max_daily_visits: 24 }); setTerritoryName(''); setTerritoryAreas([]); await load(); } finally { setBusy(false); } };
  const overrideAssignment = async () => { if (!overrideCollector || !selected.length) return; setBusy(true); try { await api.post('/collection-monitoring/assignments', { collector_id: overrideCollector, customer_ids: selected, territory_label: overrideLabel || 'Administrator override' }); setSelected([]); await load(); } finally { setBusy(false); } };
  const viewEvidence = async (id: string) => { const response = await api.get(`/collection-monitoring/registration-evidence/${id}/photo`, { responseType: 'blob' }); const url=URL.createObjectURL(response.data); window.open(url,'_blank','noopener,noreferrer'); window.setTimeout(()=>URL.revokeObjectURL(url),60_000); };
  const viewVisitProof = async (visitId: string, title: string) => {
    setLoadError('');
    setProofLoading({id:visitId,percent:null});
    try {
      const response=await api.get(`/collection-monitoring/visits/${visitId}/photo`,{
        params:{preview:1},
        responseType:'blob',
        timeout:120_000,
        onDownloadProgress:(event)=>setProofLoading({
          id:visitId,
          percent:event.total ? Math.min(100,Math.round((event.loaded/event.total)*100)) : null,
        }),
      });
      if(proofPreview) URL.revokeObjectURL(proofPreview.url);
      setProofPreview({url:URL.createObjectURL(response.data),title});
    } catch (error: any) {
      setLoadError(error.response?.status===404
        ? 'This proof photo is no longer retained. Paid-account visit photos are removed automatically for privacy.'
        : (error.response?.data?.message || 'The proof photo could not be loaded.'));
    } finally { setProofLoading(null); }
  };
  const closeProofPreview = () => { if(proofPreview) URL.revokeObjectURL(proofPreview.url); setProofPreview(null); };
  const approveMac = async (id: string) => { const mac=macInputs[id]?.trim(); if(!mac)return; setBusy(true); try { await api.put(`/collection-monitoring/registration-evidence/${id}/review`,{mac_address:mac}); await load(); } finally { setBusy(false); } };
  const saveClientQuota = async (collectorId: string) => { const quota=Number(quotaInputs[collectorId]); if(!Number.isInteger(quota)||quota<1||quota>200)return; setBusy(true); try { await api.put(`/collection-monitoring/collectors/${collectorId}/daily-client-quota`,{max_daily_visits:quota}); await load(); } finally { setBusy(false); } };

  return <DashboardLayout><main className="space-y-6 p-4 md:p-6">
    {proofPreview&&<div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4" role="dialog" aria-modal="true" aria-label="Collection proof preview"><div className="w-full max-w-3xl rounded-2xl border bg-card p-4 shadow-2xl"><div className="mb-3 flex items-center justify-between gap-3"><div><p className="font-bold">Proof of visit</p><p className="text-sm text-muted-foreground">{proofPreview.title}</p></div><button type="button" onClick={closeProofPreview} className="rounded-lg border p-2" aria-label="Close proof preview"><X className="h-5 w-5"/></button></div><img src={proofPreview.url} alt={`Proof of visit for ${proofPreview.title}`} className="max-h-[75vh] w-full rounded-xl bg-muted object-contain"/></div></div>}
    <header className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="flex items-center gap-2 text-2xl font-bold"><MapPinned className="text-primary" />Collection monitoring</h1><p className="text-sm text-muted-foreground">Supervise automatic territories, daily planning runs, coverage, and route completion.</p></div><button onClick={() => void load()} className="rounded-lg border px-3 py-2 text-sm"><RefreshCw className="mr-1 inline h-4 w-4" />Refresh</button></header>
    {loadError&&<section className="rounded-2xl border border-red-300 bg-red-50 p-4 text-sm font-medium text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200">{loadError}</section>}
    <section className={`rounded-2xl border p-4 ${data?.automation?.active ? 'border-emerald-300 bg-emerald-50 text-emerald-950 dark:bg-emerald-950/30 dark:text-emerald-100' : 'border-amber-300 bg-amber-50 text-amber-950 dark:bg-amber-950/30 dark:text-amber-100'}`}><p className="font-bold">Automatic planner: {data?.automation?.active ? 'Active' : 'Staged — production activation blocked'}</p><p className="mt-1 text-xs">Daily {data?.automation?.daily_at || 'not configured'} {data?.automation?.timezone || 'Asia/Manila'} · {data?.automation?.algorithm_version || 'version unavailable'}. Environment: {data?.automation?.environment_enabled ? 'enabled' : 'disabled'} · application: {data?.automation?.application_enabled ? 'enabled' : 'disabled'}.</p></section>
    <section className="grid gap-3 sm:grid-cols-4"><Metric label="Collectors" value={data?.collectors?.length || 0}/><Metric label="Active assignments" value={data?.assignments?.length || 0}/><Metric label="Missing coordinates" value={data?.customers_missing_coordinates || 0}/><Metric label="Unassigned customers" value={data?.unassigned_customers || 0}/></section>
    <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6"><Metric label="Target customers today" value={data?.coverage_report?.target_count || 0}/><Metric label="Remaining visits" value={data?.coverage_report?.scheduled || 0}/><Metric label="Unscheduled" value={data?.coverage_report?.unscheduled || 0}/><Metric label="Completed" value={data?.coverage_report?.completed || 0}/><Metric label="Failed visits" value={data?.coverage_report?.failed || 0}/><Metric label="Open collectible" value={money(data?.coverage_report?.total_collectible || 0)}/></section>
    <section className="rounded-2xl border bg-card p-5"><h2 className="font-bold">Customer registration evidence</h2><p className="mt-1 text-xs text-muted-foreground">Collectors capture router/ONU label photos. Only administrators and office staff can verify and save a MAC address.</p><div className="mt-4 space-y-2">{data?.registration_evidence?.map(item=><div key={item.id} className="grid gap-2 rounded-xl border p-3 text-sm md:grid-cols-[1fr_auto_auto_auto] md:items-center"><div><b>{item.customer?.full_name||'Archived customer'}</b> · {item.customer?.account_number||'Account unavailable'}<p className="text-xs text-muted-foreground">Captured by {item.collector?.name||'Archived collector'} · {item.status}{item.submitted_email?` · ${item.submitted_email}`:''}{item.submitted_phone?` · ${item.submitted_phone}`:''}</p></div><button onClick={()=>void viewEvidence(item.id)} className="rounded-lg border px-3 py-2 font-semibold">View label photo</button>{item.status==='pending'?<><input value={macInputs[item.id]||''} onChange={e=>setMacInputs(current=>({...current,[item.id]:e.target.value}))} placeholder="AA:BB:CC:DD:EE:FF" className="rounded-lg border bg-background px-3 py-2"/><button disabled={busy||!macInputs[item.id]?.trim()} onClick={()=>void approveMac(item.id)} className="rounded-lg bg-primary px-3 py-2 font-semibold text-primary-foreground disabled:opacity-40">Verify MAC</button></>:<span className="font-semibold text-emerald-700 dark:text-emerald-300">{item.verified_mac_address||item.customer?.mac_address||'Approved'}</span>}</div>)}{!data?.registration_evidence?.length&&<p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">No router-label evidence submitted.</p>}</div></section>
    <section className="rounded-2xl border bg-card p-5">
      <h2 className="font-bold">Automatic collector territories</h2>
      <p className="mt-1 text-xs text-muted-foreground">Choose one collector, a territory type, and one or more service areas. Each selected area is saved as an independent matching rule.</p>
      <div className="mt-4 grid gap-3 md:grid-cols-3">
        <label className="grid gap-1 text-xs font-semibold">Collector<select value={territoryCollector} onChange={e=>setTerritoryCollector(e.target.value)} className="rounded-lg border bg-background p-2 text-sm"><option value="">Select collector</option>{data?.collectors.map(item=><option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        <label className="grid gap-1 text-xs font-semibold">Territory name<select value={territoryName} onChange={e=>{setTerritoryName(e.target.value);setTerritoryAreas([])}} className="rounded-lg border bg-background p-2 text-sm"><option value="">Select territory type</option>{data?.territory_name_options.map(item=><option key={item} value={item}>{item}</option>)}</select></label>
        <label className="grid gap-1 text-xs font-semibold">Address / area <span className="font-normal text-muted-foreground">Select multiple areas with Ctrl/Command or the phone selector.</span><select multiple disabled={!territoryName||territoryName==='Fallback territory'} value={territoryAreas} onChange={e=>setTerritoryAreas(Array.from(e.currentTarget.selectedOptions,option=>option.value))} className="min-h-40 rounded-lg border bg-background p-2 text-sm disabled:opacity-50">{data?.territory_area_options.map(item=><option key={item} value={item}>{item}</option>)}</select></label>
      </div>
      <p className="mt-2 text-xs text-muted-foreground">Selected: {territoryName==='Fallback territory'?'All unmatched addresses':territoryAreas.length?territoryAreas.join(', '):'No areas selected'}</p>
      <button disabled={busy||!territoryCollector||!territoryName||(territoryName!=='Fallback territory'&&!territoryAreas.length)} onClick={()=>void saveTerritory()} className="mt-3 rounded-lg bg-primary px-4 py-2 font-semibold text-primary-foreground disabled:opacity-50">Save territory areas</button>
      <div className="mt-5 border-t pt-4"><h3 className="text-sm font-bold">Clients per daily schedule</h3><p className="mt-1 text-xs text-muted-foreground">Super Admin, Admin, and Office Admin can set the maximum number of customer stops assigned to each collector. Extra eligible customers remain unscheduled for monitoring.</p><div className="mt-3 grid gap-2 md:grid-cols-2">{data?.collectors.filter(collector=>(data?.territories||[]).some(territory=>territory.collector?.id===collector.id&&territory.is_active)).map(collector=><div key={collector.id} className="flex flex-wrap items-end gap-2 rounded-lg border p-3"><label className="grid flex-1 gap-1 text-xs font-semibold">{collector.name}<input type="number" min="1" max="200" step="1" value={quotaInputs[collector.id]||''} onChange={event=>setQuotaInputs(current=>({...current,[collector.id]:event.target.value}))} className="rounded-lg border bg-background px-3 py-2 text-sm"/></label><button type="button" disabled={busy||!Number.isInteger(Number(quotaInputs[collector.id]))||Number(quotaInputs[collector.id])<1||Number(quotaInputs[collector.id])>200} onClick={()=>void saveClientQuota(collector.id)} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">Save quota</button></div>)}</div></div>
      <div className="mt-4 grid gap-2 md:grid-cols-2">{data?.territories.map(item=><div key={item.id} className="rounded-lg border p-3 text-sm"><b>{item.name}</b> · {item.collector?.name||'Archived collector'}<p className="text-xs text-muted-foreground">{item.is_fallback ? 'Fallback for unmatched addresses' : `Matches “${item.address_pattern}”`} · {item.max_daily_visits}/day · {item.is_active ? 'active' : 'inactive'}</p></div>)}</div>
    </section>
    <section className="rounded-2xl border bg-card p-5"><h2 className="font-bold">Planning run monitoring</h2><div className="mt-3 overflow-x-auto"><table className="min-w-[760px] w-full text-sm"><thead className="text-left text-xs uppercase text-muted-foreground"><tr><th className="p-2">Date</th><th className="p-2">Status</th><th className="p-2">Eligible</th><th className="p-2">Assigned</th><th className="p-2">Exceptions</th><th className="p-2">Plans</th><th className="p-2">Scheduled</th><th className="p-2">Unscheduled</th></tr></thead><tbody>{data?.planner_runs.map(run=><tr key={run.id} className="border-t"><td className="p-2">{String(run.run_date).slice(0,10)}</td><td className="p-2 capitalize">{run.status}{run.dry_run ? ' · audit' : ''}</td><td className="p-2">{run.eligible_customers}</td><td className="p-2">{run.new_assignments}</td><td className="p-2">{run.unassigned_exceptions}</td><td className="p-2">{run.plans_generated}</td><td className="p-2">{run.scheduled_visits}</td><td className="p-2">{run.unscheduled_visits}</td></tr>)}</tbody></table></div></section>
    <section className="grid gap-4 lg:grid-cols-2">{data?.plans.map(plan=>{
      const visitRows=(plan.stops||[]).filter(stop=>stop.customer).flatMap(stop=>(stop.visits||[]).map(visit=>({stop,visit})));
      const targets=plan.stops.filter(stop=>stop.status!=='unscheduled');
      const remaining=targets.filter(stop=>['planned','in_progress'].includes(stop.status));
      const completed=targets.filter(stop=>stop.status==='completed');
      return <article key={plan.id} className="rounded-2xl border bg-card p-5"><div className="flex flex-wrap justify-between gap-2"><div><h2 className="font-bold">{plan.collector?.name||'Archived collector'}</h2><p className="text-xs text-muted-foreground">Schedule · {String(plan.plan_date).slice(0,10)}</p></div><span className="text-sm font-semibold">{completed.length}/{targets.length} customers completed</span></div><div className="mt-3 grid grid-cols-2 gap-2 text-center text-xs sm:grid-cols-4"><Mini value={targets.length} label="Target customers"/><Mini value={remaining.length} label="Remaining customers"/><Mini value={targets.filter(s=>s.location_source==='verified_gps').length} label="GPS customers"/><Mini value={targets.filter(s=>s.location_source==='address_only').length} label="Address-only customers"/></div><div className="mt-4 border-t pt-4"><div className="flex items-center gap-2"><Camera className="h-4 w-4 text-primary"/><h3 className="text-sm font-bold">Visit and attempt evidence</h3></div><div className="mt-2 space-y-2">{visitRows.map(({stop,visit})=><div key={visit.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 text-sm"><div><b>{stop.customer?.full_name||'Archived customer'}</b><p className="text-xs text-muted-foreground">{visit.outcome?.replaceAll('_',' ')||visit.status.replaceAll('_',' ')}{visit.completed_at?` · ${new Date(visit.completed_at).toLocaleString('en-PH',{timeZone:'Asia/Manila'})}`:''}</p></div>{visit.has_photo?<button type="button" disabled={proofLoading?.id===visit.id} onClick={()=>void viewVisitProof(visit.id,`${stop.customer?.full_name||'Archived customer'} · ${stop.customer?.account_number||'Account unavailable'}`)} className="rounded-lg border border-primary px-3 py-2 text-xs font-semibold text-primary disabled:cursor-wait disabled:opacity-70">{proofLoading?.id===visit.id?`Loading photo${proofLoading.percent!==null?` · ${proofLoading.percent}%`:'…'}`:'Preview proof'}</button>:<span className="text-xs text-muted-foreground">No retained photo</span>}</div>)}{!visitRows.length&&<p className="rounded-lg border border-dashed p-3 text-center text-xs text-muted-foreground">No collection attempts recorded for this plan.</p>}</div><p className="mt-2 text-[11px] text-muted-foreground">Proof photos are private. They are removed automatically after the account is fully settled.</p></div></article>;
    })}</section>
    <details className="rounded-2xl border bg-card p-5"><summary className="cursor-pointer font-bold">Emergency assignment override</summary><p className="mt-2 text-xs text-muted-foreground">Normal planning uses territories. This override is only for exceptions and remains auditable.</p><div className="mt-3 grid gap-3 md:grid-cols-3"><select value={overrideCollector} onChange={e=>setOverrideCollector(e.target.value)} className="rounded-lg border bg-background p-2"><option value="">Select collector</option>{data?.collectors.map(item=><option key={item.id} value={item.id}>{item.name}</option>)}</select><input value={overrideLabel} onChange={e=>setOverrideLabel(e.target.value)} placeholder="Override reason" className="rounded-lg border bg-background p-2"/><input value={search} onChange={e=>setSearch(e.target.value)} placeholder="Search unassigned customer" className="rounded-lg border bg-background p-2"/></div><div className="mt-3 max-h-64 overflow-auto rounded-xl border">{candidates.map(item=><label key={item.id} className="flex gap-3 border-b p-3 text-sm"><input type="checkbox" checked={selected.includes(item.id)} onChange={e=>setSelected(current=>e.target.checked?[...current,item.id]:current.filter(id=>id!==item.id))}/><span><b>{item.full_name}</b> · {item.account_number}<small className="block text-muted-foreground">{item.address || 'Address not recorded'}</small></span></label>)}</div><button disabled={busy||!overrideCollector||!selected.length} onClick={()=>void overrideAssignment()} className="mt-3 rounded-lg border border-primary px-4 py-2 font-semibold text-primary disabled:opacity-50">Apply override</button></details>
  </main></DashboardLayout>;
}

function Metric({label,value}:{label:string;value:string|number}){return <div className="rounded-xl border bg-card p-4"><p className="text-xs text-muted-foreground">{label}</p><p className="text-2xl font-bold">{value}</p></div>}
function Mini({label,value}:{label:string;value:string|number}){return <div className="rounded-lg bg-muted p-2"><b className="block text-base">{value}</b>{label}</div>}
