import { useEffect, useState } from 'react';
import { CalendarDays, CreditCard, ShieldCheck } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import service, { saasError, type TenantSubscriptionSummary } from '@/services/saasTenantService';

const date = (value?:string|null):string => value ? new Intl.DateTimeFormat('en-PH',{dateStyle:'long',timeZone:'Asia/Manila'}).format(new Date(value)) : 'Not set';

export default function TenantSubscriptionPage():React.JSX.Element {
  const [data,setData]=useState<TenantSubscriptionSummary|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
  const load=()=>service.subscription().then(setData).catch((reason)=>setError(saasError(reason)));
  useEffect(()=>{void load();},[]);
  const pay=async()=>{setBusy(true);setError('');try{const checkout=await service.startSubscriptionCheckout();window.location.assign(checkout.checkout_url);}catch(reason){setError(saasError(reason));setBusy(false);}};
  return <DashboardLayout headerTitle="Subscription" headerSubtitle="Company plan, trial, billing, and verified payments">
    {error&&<p role="alert" className="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm font-semibold text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-100">{error}</p>}
    {!data?<section className="rounded-xl border border-border bg-card p-6 text-foreground">Loading subscription...</section>:<div className="space-y-5">
      <section className="rounded-xl border border-border bg-card p-5 text-foreground shadow-sm">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p className="text-sm font-bold uppercase tracking-wider text-primary">{data.administrator.role}</p><h1 className="mt-1 text-2xl font-black">{data.tenant.name}</h1><p className="text-sm text-muted-foreground">Company ID: {data.tenant.slug}</p></div><span className="w-fit rounded-full bg-emerald-100 px-3 py-1 text-sm font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-100">{data.subscription.status}</span></div>
        <div className="mt-5 grid gap-3 sm:grid-cols-3"><article className="rounded-lg border border-border bg-muted/30 p-4"><CalendarDays className="h-5 w-5 text-primary"/><p className="mt-2 text-sm text-muted-foreground">Free trial remaining</p><p className="text-2xl font-black">{data.trial_days_remaining} days</p><p className="text-xs text-muted-foreground">Ends {date(data.subscription.trial_ends_at)}</p></article><article className="rounded-lg border border-border bg-muted/30 p-4"><CreditCard className="h-5 w-5 text-primary"/><p className="mt-2 text-sm text-muted-foreground">Plan</p><p className="text-xl font-black">PHP {Number(data.plan.amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</p><p className="text-xs text-muted-foreground">Monthly · {data.plan.grace_days}-day grace period</p></article><article className="rounded-lg border border-border bg-muted/30 p-4"><ShieldCheck className="h-5 w-5 text-primary"/><p className="mt-2 text-sm text-muted-foreground">Next payment due</p><p className="text-lg font-black">{date(data.subscription.next_payment_due_at || data.subscription.trial_ends_at)}</p><p className="text-xs text-muted-foreground">Status: {data.subscription.payment_status || 'not due'}</p></article></div>
        {data.can_pay&&<button type="button" disabled={busy} onClick={()=>void pay()} className="mt-5 w-full rounded-lg bg-primary px-4 py-3 font-bold text-primary-foreground disabled:opacity-60">{busy?'Opening secure checkout...':'Pay or renew subscription'}</button>}
        <p className="mt-2 text-xs text-muted-foreground">Your subscription changes only after PayMongo confirms the payment through its signed webhook.</p>
      </section>
      <section className="rounded-xl border border-border bg-card p-5 text-foreground shadow-sm"><h2 className="text-lg font-bold">Payment history</h2><div className="mt-4 space-y-2">{data.payments.length===0?<p className="text-sm text-muted-foreground">No subscription payments yet.</p>:data.payments.map(payment=><article key={payment.id} className="flex justify-between rounded-lg border border-border p-3 text-sm"><div><p className="font-semibold">PHP {Number(payment.amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</p><p className="text-xs text-muted-foreground">{payment.provider_reference || 'Pending reference'} · {date(payment.created_at)}</p></div><span className="font-bold uppercase">{payment.status}</span></article>)}</div></section>
    </div>}
  </DashboardLayout>;
}
