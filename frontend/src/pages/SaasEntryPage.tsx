import { Building2, CheckCircle2, LockKeyhole, ShieldCheck } from 'lucide-react';
import FiberOpticBackground from '@/components/FiberOpticBackground';

type Props = { surface: 'app' | 'admin' };

export default function SaasEntryPage({surface}:Props): React.JSX.Element {
  const tenantApp=surface==='app';
  return <main className="relative min-h-screen overflow-hidden text-white">
    <FiberOpticBackground />
    <div className="relative z-10 mx-auto flex min-h-screen max-w-6xl items-center px-4 py-12 sm:px-6">
      <section className="grid w-full gap-6 lg:grid-cols-[1.1fr_.9fr]">
        <div className="rounded-3xl border border-white/25 bg-slate-950/80 p-7 shadow-2xl backdrop-blur-xl sm:p-10">
          <img src="/solarnet-company-logo-192.png?v=20261015-official" alt="SolarNet" className="h-16 w-16 object-contain" />
          <p className="mt-6 text-sm font-bold uppercase tracking-[.22em] text-cyan-300">SolarNet ISP Billing SaaS</p>
          <h1 className="mt-3 text-4xl font-black leading-tight sm:text-5xl">{tenantApp?'ISP company workspace':'Platform administration'}</h1>
          <p className="mt-5 max-w-2xl text-base leading-7 text-slate-200">{tenantApp
            ? 'One secure workspace for each subscribed ISP company, with isolated staff, customers, billing, collections, and company settings.'
            : "SolarNet's protected control plane for tenant onboarding, subscription plans, trials, payments, access control, and platform oversight."}</p>
          <div className="mt-8 rounded-2xl border border-amber-300/50 bg-amber-950/60 p-4 text-amber-100">
            <div className="flex gap-3"><LockKeyhole className="mt-0.5 h-5 w-5 shrink-0"/><div><p className="font-bold">Secure staged access</p><p className="mt-1 text-sm leading-6">Sign-in remains locked until tenant isolation and authorization verification are complete. Existing SolarNet billing continues separately at billing.solarnetportal.com.</p></div></div>
          </div>
        </div>
        <aside className="rounded-3xl border border-white/25 bg-white/95 p-7 text-slate-950 shadow-2xl backdrop-blur-xl sm:p-9">
          <div className="flex items-center gap-3"><div className="rounded-2xl bg-blue-100 p-3 text-blue-700">{tenantApp?<Building2/>:<ShieldCheck/>}</div><div><p className="text-xs font-bold uppercase tracking-wider text-blue-700">{tenantApp?'app.solarnetportal.com':'admin.solarnetportal.com'}</p><h2 className="text-2xl font-black">Foundation ready</h2></div></div>
          <div className="mt-7 space-y-4">{(tenantApp?[
            'Tenant-aware company sign-in','7-day trial and subscription status','Company-isolated operational workspace','No access to another ISP\'s records',
          ]:[
            'Tenant and subscription management','Trial and payment oversight','Platform-only access controls','Auditable support access',
          ]).map(item=><div key={item} className="flex items-start gap-3 rounded-xl bg-slate-100 p-3"><CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600"/><span className="font-semibold">{item}</span></div>)}</div>
          <a href="https://billing.solarnetportal.com" className="mt-8 block rounded-xl bg-blue-700 px-5 py-3 text-center font-bold text-white hover:bg-blue-800">Open existing SolarNet billing</a>
        </aside>
      </section>
    </div>
  </main>;
}
