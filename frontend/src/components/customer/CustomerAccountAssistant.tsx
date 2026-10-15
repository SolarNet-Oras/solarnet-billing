import { useState } from 'react';
import { MessageCircle, Send, ShieldCheck, X } from 'lucide-react';
import customerPortalService from '../../services/customerPortalService';
import type { ReferralServicePlan } from '../../services/customerPortalService';
import { formatPHP } from '../../lib/currency';

type Line = { role: 'customer' | 'assistant'; text: string };
type Qr = { qr_image_url?: string | null; invoice_number: string; amount: number; expires_at?: string | null };

export default function CustomerAccountAssistant(): React.JSX.Element {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(false);
  const [qr, setQr] = useState<Qr | null>(null);
  const [action, setAction] = useState<'contact_update' | 'plan_request' | null>(null);
  const [verification, setVerification] = useState({ full_name: '', account_number: '', email: '', contact_number: '', service_plan_id: '', confirmed: false });
  const [plans, setPlans] = useState<ReferralServicePlan[]>([]);
  const [lines, setLines] = useState<Line[]>([{ role: 'assistant', text: 'Hi! Ask about your account status, open billing, payments, internet concerns, contact information, or plan requests. Sensitive changes require exact account verification and confirmation.' }]);

  const ask = async (message: string): Promise<void> => {
    const text = message.trim();
    if (!text || busy) return;
    setDraft(''); setBusy(true); setQr(null);
    setLines(current => [...current, { role: 'customer', text }]);
    try {
      const response = await customerPortalService.chatWithCustomerAssistant(text);
      setLines(current => [...current, { role: 'assistant', text: response.assistant }]);
      setAction(response.action);
      if (response.action === 'plan_request' && plans.length === 0) setPlans(await customerPortalService.getReferralServicePlans());
      if (response.payment_invoice) {
        const payment = await customerPortalService.startQrPhPayment(response.payment_invoice.id);
        setQr(payment);
      }
    } catch (error: any) {
      setLines(current => [...current, { role: 'assistant', text: error.response?.data?.message || 'The assistant is temporarily unavailable. No account change was made.' }]);
    } finally { setBusy(false); }
  };

  const submitVerifiedChange = async (): Promise<void> => {
    if (!action || busy) return;
    setBusy(true);
    try {
      const result = action === 'contact_update'
        ? await customerPortalService.updateContactWithAssistant(verification)
        : await customerPortalService.requestPlanWithAssistant(verification);
      setLines(current => [...current, { role: 'assistant', text: result.message }]);
      setAction(null);
      setVerification({ full_name: '', account_number: '', email: '', contact_number: '', service_plan_id: '', confirmed: false });
    } catch (error: any) {
      setLines(current => [...current, { role: 'assistant', text: error.response?.data?.message || 'The verified request could not be completed. No change was made.' }]);
    } finally { setBusy(false); }
  };

  return <div className="fixed bottom-5 right-4 z-[80] sm:right-6">
    {open && <section className="mb-3 flex max-h-[70vh] w-[min(24rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-3xl border border-cyan-200 bg-white shadow-2xl">
      <header className="flex items-center justify-between bg-gradient-to-r from-blue-700 to-cyan-600 p-4 text-white"><div><h2 className="font-bold">SolarNet Customer Assistant</h2><p className="text-xs text-cyan-50">Your account only · Limited verified actions</p></div><button onClick={() => setOpen(false)} aria-label="Close assistant"><X className="h-5 w-5" /></button></header>
      <div className="overflow-y-auto p-4"><div className="mb-3 flex gap-2 rounded-xl bg-emerald-50 p-3 text-xs text-emerald-900"><ShieldCheck className="h-4 w-4 shrink-0" />Contact updates require exact verification. Plan changes require staff approval. No unsuspension, discounts, billing edits, router commands, or access to other accounts.</div>
        <div className="space-y-2">{lines.map((line, index) => <div key={index} className={`flex ${line.role === 'customer' ? 'justify-end' : 'justify-start'}`}><p className={`max-w-[88%] rounded-2xl px-3 py-2 text-sm leading-5 ${line.role === 'customer' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800'}`}>{line.text}</p></div>)}</div>
        {qr?.qr_image_url && <div className="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-center"><p className="font-bold text-emerald-950">Scan QRPh to pay</p><p className="text-xs text-emerald-800">{qr.invoice_number} · {formatPHP(qr.amount)}</p><img src={qr.qr_image_url} alt={`QRPh payment for ${qr.invoice_number}`} className="mx-auto mt-3 h-56 w-56 rounded-xl bg-white p-2" /><p className="mt-2 text-xs text-emerald-900">Payment posts only after secure provider confirmation.</p></div>}
        {action && <div className="mt-4 space-y-2 rounded-2xl border border-blue-200 bg-blue-50 p-4"><p className="text-sm font-bold text-blue-950">Verify your SolarNet account</p><input value={verification.full_name} onChange={event => setVerification(current => ({ ...current, full_name: event.target.value }))} placeholder="Exact registered full name" className="w-full rounded-lg border bg-white px-3 py-2 text-sm" /><input value={verification.account_number} onChange={event => setVerification(current => ({ ...current, account_number: event.target.value }))} placeholder="Exact account number" className="w-full rounded-lg border bg-white px-3 py-2 text-sm" />
          {action === 'contact_update' ? <><input type="email" value={verification.email} onChange={event => setVerification(current => ({ ...current, email: event.target.value }))} placeholder="New Gmail/email (optional)" className="w-full rounded-lg border bg-white px-3 py-2 text-sm" /><input value={verification.contact_number} onChange={event => setVerification(current => ({ ...current, contact_number: event.target.value }))} placeholder="New mobile number, e.g. 09XXXXXXXXX" className="w-full rounded-lg border bg-white px-3 py-2 text-sm" /></> : <select value={verification.service_plan_id} onChange={event => setVerification(current => ({ ...current, service_plan_id: event.target.value }))} className="w-full rounded-lg border bg-white px-3 py-2 text-sm"><option value="">Choose requested plan or promo rate</option>{plans.map(plan => <option key={plan.id} value={plan.id}>{plan.name} · {formatPHP(plan.price)}/month</option>)}</select>}
          <label className="flex gap-2 text-xs text-blue-950"><input type="checkbox" checked={verification.confirmed} onChange={event => setVerification(current => ({ ...current, confirmed: event.target.checked }))} />I confirm these details are real, exact, and belong to my signed-in account.</label><button type="button" onClick={() => void submitVerifiedChange()} disabled={busy || !verification.full_name || !verification.account_number || !verification.confirmed || (action === 'contact_update' ? (!verification.email && !verification.contact_number) : !verification.service_plan_id)} className="w-full rounded-xl bg-blue-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{action === 'contact_update' ? 'Confirm contact update' : 'Submit for staff approval'}</button></div>}
        <div className="mt-3 flex flex-wrap gap-2">{['What is my balance?', 'What is my account status?', 'I want to pay by QRPh', 'Update my information', 'Request plan upgrade', 'I have an internet concern'].map(prompt => <button key={prompt} disabled={busy} onClick={() => void ask(prompt)} className="rounded-full border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">{prompt}</button>)}</div>
      </div>
      <form onSubmit={event => { event.preventDefault(); void ask(draft); }} className="flex gap-2 border-t p-3"><input value={draft} onChange={event => setDraft(event.target.value)} maxLength={500} placeholder="Ask SolarNet…" className="min-w-0 flex-1 rounded-xl border px-3 py-2 text-sm" /><button disabled={busy || !draft.trim()} className="rounded-xl bg-blue-600 px-3 text-white disabled:opacity-50"><Send className="h-4 w-4" /></button></form>
    </section>}
    <button onClick={() => setOpen(current => !current)} className="ml-auto flex h-16 w-16 items-center justify-center overflow-hidden rounded-full border-2 border-cyan-300 bg-slate-950 shadow-xl" aria-label="Open SolarNet customer assistant"><img src="/solarnet-ai-chat.png" alt="" className="h-full w-full object-cover" /><MessageCircle className="sr-only" /></button>
  </div>;
}
