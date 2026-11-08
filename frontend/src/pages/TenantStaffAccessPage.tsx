import { useEffect, useState } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import service, { saasError } from '@/services/saasTenantService';

type AccessRequest = { id:string; name:string; email:string; phone?:string|null; requested_role:string; status:string; created_at:string; reviewed_at?:string|null };

const roleLabels:Record<string,string>={tenant_staff_admin:'Admin Staff',tenant_collector:'Collector',tenant_technician:'Technician',tenant_collector_technician:'Collector + Technician',tenant_staff:'Staff'};
const roleLabel=(role:string):string=>roleLabels[role]||role.replaceAll('_',' ');

export default function TenantStaffAccessPage(): React.JSX.Element {
  const [rows,setRows]=useState<AccessRequest[]>([]),[error,setError]=useState(''),[busy,setBusy]=useState('');
  const load=()=>service.staffRequests().then(setRows).catch((reason)=>setError(saasError(reason)));
  useEffect(()=>{void load();},[]);
  const review=async(id:string,decision:'approve'|'reject')=>{setBusy(id);setError('');try{await service.reviewStaffRequest(id,decision);await load();}catch(reason){setError(saasError(reason));}finally{setBusy('');}};
  return <DashboardLayout headerTitle="Staff access" headerSubtitle="Tenant Super Administrator approval">
    <section className="rounded-xl border border-border bg-card p-5 shadow-sm">
      <h1 className="text-xl font-bold text-foreground">Company staff requests</h1>
      <p className="mt-1 text-sm text-muted-foreground">Approved staff inherit this company&apos;s workspace subscription. They never receive SolarNet private billing access.</p>
      {error&&<p className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-100">{error}</p>}
      <div className="mt-5 space-y-3">{rows.length===0?<p className="text-sm text-muted-foreground">No staff access requests.</p>:rows.map(row=><article key={row.id} className="flex flex-col gap-3 rounded-lg border border-border p-4 sm:flex-row sm:items-center sm:justify-between"><div><p className="font-semibold text-foreground">{row.name}</p><p className="text-sm text-muted-foreground">{row.email}{row.phone?` · ${row.phone}`:''}</p><p className="mt-1 text-xs font-semibold text-primary">Requested role: {roleLabel(row.requested_role)}</p><p className="mt-1 text-xs uppercase tracking-wide text-muted-foreground">{row.status}</p></div>{row.status==='pending'&&<div className="flex gap-2"><button disabled={busy===row.id} onClick={()=>void review(row.id,'reject')} className="rounded-md border border-border px-3 py-2 text-sm font-semibold">Reject</button><button disabled={busy===row.id} onClick={()=>void review(row.id,'approve')} className="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground">Approve {roleLabel(row.requested_role)}</button></div>}</article>)}</div>
    </section>
  </DashboardLayout>;
}
