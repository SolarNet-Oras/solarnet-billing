import React from 'react';
import { Laptop, ShieldCheck, Smartphone, UserCheck } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';

const EmployeeDeviceControllerPage: React.FC = () => (
  <DashboardLayout headerTitle="Employee Device Controller" headerSubtitle="Super Administrator secure device workspace">
    <div className="space-y-5">
      <section className="overflow-hidden rounded-3xl border border-blue-500/20 bg-gradient-to-br from-slate-950 via-blue-950 to-slate-950 p-6 text-white shadow-2xl shadow-blue-950/20">
        <div className="flex flex-col justify-between gap-5 md:flex-row md:items-center">
          <div><div className="flex items-center gap-2 text-cyan-300"><ShieldCheck className="h-5 w-5"/><span className="text-xs font-bold uppercase tracking-[.2em]">Super Administrator only</span></div><h1 className="mt-3 text-3xl font-bold">SolarNet employee device control</h1><p className="mt-2 max-w-3xl text-sm leading-6 text-slate-300">Consent-based support for company-enrolled Windows and Android devices. Screen access requires an employee prompt; guarded actions require a reason and remain in the audit trail.</p></div>
          <span className="rounded-full border border-amber-400/30 bg-amber-400/10 px-4 py-2 text-xs font-semibold text-amber-200">Agent service not connected</span>
        </div>
      </section>
      <section className="grid gap-4 md:grid-cols-3">
        {[['Enrolled devices','0',Laptop],['Online now','0',Smartphone],['Pending approvals','0',UserCheck]].map(([label,value,Icon]) => { const DeviceIcon = Icon as typeof Laptop; return <article key={String(label)} className="rounded-2xl border border-border bg-card p-5 shadow-sm"><DeviceIcon className="h-5 w-5 text-blue-500"/><p className="mt-5 text-sm text-muted-foreground">{String(label)}</p><p className="mt-1 text-3xl font-bold text-foreground">{String(value)}</p></article>; })}
      </section>
      <section className="rounded-2xl border border-dashed border-border bg-card p-10 text-center"><h2 className="text-xl font-bold text-foreground">No employee devices enrolled</h2><p className="mx-auto mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">The controller interface is ready. Real enrollment stays disabled until signed Windows and Android agents, device certificates, heartbeat APIs, command signing, consent prompts, and immutable audits are connected.</p><button disabled className="mt-5 cursor-not-allowed rounded-xl bg-muted px-5 py-3 text-sm font-semibold text-muted-foreground">Enroll device — agent required</button></section>
    </div>
  </DashboardLayout>
);

export default EmployeeDeviceControllerPage;
