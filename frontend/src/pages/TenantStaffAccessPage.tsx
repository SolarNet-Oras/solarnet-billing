import { useEffect, useState } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import service, { saasError } from '@/services/saasTenantService';

type AccessRequest = { id:string; name:string; email:string; phone?:string|null; requested_role:string; status:string };
type StaffMember = { id:string; name:string; email:string; phone?:string|null; membership_role:string; status:string };

const roleOptions = [
  ['tenant_staff_admin','Admin Staff'],
  ['tenant_collector','Collector'],
  ['tenant_technician','Technician'],
  ['tenant_collector_technician','Collector + Technician'],
] as const;
const roleLabels:Record<string,string> = {
  tenant_staff_admin:'Admin Staff',
  tenant_collector:'Collector',
  tenant_technician:'Technician',
  tenant_collector_technician:'Collector + Technician',
  tenant_staff:'Staff',
};
const roleLabel=(role:string):string=>roleLabels[role]||role.replaceAll('_',' ');

export default function TenantStaffAccessPage(): React.JSX.Element {
  const [requests,setRequests]=useState<AccessRequest[]>([]);
  const [members,setMembers]=useState<StaffMember[]>([]);
  const [roles,setRoles]=useState<Record<string,string>>({});
  const [error,setError]=useState('');
  const [busy,setBusy]=useState('');

  const load=async()=>{
    try {
      const [requestRows,memberRows]=await Promise.all([service.staffRequests(),service.staffMembers()]);
      setRequests(requestRows);
      setMembers(memberRows);
      setRoles(Object.fromEntries(memberRows.map((member:StaffMember)=>[member.id,member.membership_role])));
    } catch(reason) { setError(saasError(reason)); }
  };
  useEffect(()=>{void load();},[]);

  const review=async(id:string,decision:'approve'|'reject')=>{
    setBusy(id);setError('');
    try{await service.reviewStaffRequest(id,decision);await load();}catch(reason){setError(saasError(reason));}finally{setBusy('');}
  };
  const updateRole=async(member:StaffMember)=>{
    setBusy(member.id);setError('');
    try{await service.updateStaffMemberRole(member.id,roles[member.id]);await load();}catch(reason){setError(saasError(reason));}finally{setBusy('');}
  };
  const removeMember=async(member:StaffMember)=>{
    if(!window.confirm(`Remove ${member.name}'s access to this company workspace? Their account and other company access will not be deleted.`))return;
    setBusy(member.id);setError('');
    try{await service.removeStaffMember(member.id);await load();}catch(reason){setError(saasError(reason));}finally{setBusy('');}
  };

  return <DashboardLayout headerTitle="Staff access" headerSubtitle="Tenant Super Administrator approval">
    <div className="space-y-5">
      <section className="rounded-xl border border-border bg-card p-5 shadow-sm">
        <h1 className="text-xl font-bold text-foreground">Approved company staff</h1>
        <p className="mt-1 text-sm text-muted-foreground">Change a staff member&apos;s company role or remove only their access to this workspace.</p>
        {error&&<p className="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-100">{error}</p>}
        <div className="mt-5 space-y-3">{members.length===0?<p className="text-sm text-muted-foreground">No approved staff members.</p>:members.map(member=><article key={member.id} className="flex flex-col gap-3 rounded-lg border border-border p-4 lg:flex-row lg:items-center lg:justify-between">
          <div><p className="font-semibold text-foreground">{member.name}</p><p className="text-sm text-muted-foreground">{member.email}{member.phone?` · ${member.phone}`:''}</p><p className="mt-1 text-xs font-semibold text-primary">Current role: {roleLabel(member.membership_role)}</p></div>
          <div className="flex flex-col gap-2 sm:flex-row">
            <select value={roles[member.id]||member.membership_role} onChange={event=>setRoles(current=>({...current,[member.id]:event.target.value}))} className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground">
              {member.membership_role==='tenant_staff'&&<option value="tenant_staff">Staff (legacy)</option>}
              {roleOptions.map(([value,label])=><option key={value} value={value}>{label}</option>)}
            </select>
            <button disabled={busy===member.id||roles[member.id]===member.membership_role} onClick={()=>void updateRole(member)} className="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">Update role</button>
            <button disabled={busy===member.id} onClick={()=>void removeMember(member)} className="rounded-md border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-700 dark:border-rose-800 dark:text-rose-300">Remove access</button>
          </div>
        </article>)}</div>
      </section>

      <section className="rounded-xl border border-border bg-card p-5 shadow-sm">
        <h2 className="text-xl font-bold text-foreground">Company staff requests</h2>
        <p className="mt-1 text-sm text-muted-foreground">Approved staff inherit this company&apos;s workspace subscription. They never receive SolarNet private billing access.</p>
        <div className="mt-5 space-y-3">{requests.length===0?<p className="text-sm text-muted-foreground">No staff access requests.</p>:requests.map(row=><article key={row.id} className="flex flex-col gap-3 rounded-lg border border-border p-4 sm:flex-row sm:items-center sm:justify-between"><div><p className="font-semibold text-foreground">{row.name}</p><p className="text-sm text-muted-foreground">{row.email}{row.phone?` · ${row.phone}`:''}</p><p className="mt-1 text-xs font-semibold text-primary">Requested role: {roleLabel(row.requested_role)}</p><p className="mt-1 text-xs uppercase tracking-wide text-muted-foreground">{row.status}</p></div>{row.status==='pending'&&<div className="flex gap-2"><button disabled={busy===row.id} onClick={()=>void review(row.id,'reject')} className="rounded-md border border-border px-3 py-2 text-sm font-semibold">Reject</button><button disabled={busy===row.id} onClick={()=>void review(row.id,'approve')} className="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground">Approve {roleLabel(row.requested_role)}</button></div>}</article>)}</div>
      </section>
    </div>
  </DashboardLayout>;
}
