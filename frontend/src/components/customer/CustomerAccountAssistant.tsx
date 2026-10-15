import { useState } from 'react';
import { MessageCircle, Send, ShieldCheck, X } from 'lucide-react';
import customerPortalService from '../../services/customerPortalService';
import { formatPHP } from '../../lib/currency';

type Line = { role: 'customer' | 'assistant'; text: string };
type Qr = { qr_image_url?: string | null; invoice_number: string; amount: number; expires_at?: string | null };

export default function CustomerAccountAssistant(): React.JSX.Element {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(false);
  const [qr, setQr] = useState<Qr | null>(null);
  const [lines, setLines] = useState<Line[]>([{ role: 'assistant', text: 'Hi! Ask about your account status, open billing, how to pay, or an internet concern. I can only read your own account and cannot change it.' }]);

  const ask = async (message: string): Promise<void> => {
    const text = message.trim();
    if (!text || busy) return;
    setDraft(''); setBusy(true); setQr(null);
    setLines(current => [...current, { role: 'customer', text }]);
    try {
      const response = await customerPortalService.chatWithCustomerAssistant(text);
      setLines(current => [...current, { role: 'assistant', text: response.assistant }]);
      if (response.payment_invoice) {
        const payment = await customerPortalService.startQrPhPayment(response.payment_invoice.id);
        setQr(payment);
      }
    } catch (error: any) {
      setLines(current => [...current, { role: 'assistant', text: error.response?.data?.message || 'The assistant is temporarily unavailable. No account change was made.' }]);
    } finally { setBusy(false); }
  };

  return <div className="fixed bottom-5 right-4 z-[80] sm:right-6">
    {open && <section className="mb-3 flex max-h-[70vh] w-[min(24rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-3xl border border-cyan-200 bg-white shadow-2xl">
      <header className="flex items-center justify-between bg-gradient-to-r from-blue-700 to-cyan-600 p-4 text-white"><div><h2 className="font-bold">SolarNet Customer Assistant</h2><p className="text-xs text-cyan-50">Your account only · Read-only</p></div><button onClick={() => setOpen(false)} aria-label="Close assistant"><X className="h-5 w-5" /></button></header>
      <div className="overflow-y-auto p-4"><div className="mb-3 flex gap-2 rounded-xl bg-emerald-50 p-3 text-xs text-emerald-900"><ShieldCheck className="h-4 w-4 shrink-0" />No unsuspension, discounts, billing edits, router commands, or access to other accounts.</div>
        <div className="space-y-2">{lines.map((line, index) => <div key={index} className={`flex ${line.role === 'customer' ? 'justify-end' : 'justify-start'}`}><p className={`max-w-[88%] rounded-2xl px-3 py-2 text-sm leading-5 ${line.role === 'customer' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800'}`}>{line.text}</p></div>)}</div>
        {qr?.qr_image_url && <div className="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-center"><p className="font-bold text-emerald-950">Scan QRPh to pay</p><p className="text-xs text-emerald-800">{qr.invoice_number} · {formatPHP(qr.amount)}</p><img src={qr.qr_image_url} alt={`QRPh payment for ${qr.invoice_number}`} className="mx-auto mt-3 h-56 w-56 rounded-xl bg-white p-2" /><p className="mt-2 text-xs text-emerald-900">Payment posts only after secure provider confirmation.</p></div>}
        <div className="mt-3 flex flex-wrap gap-2">{['What is my balance?', 'What is my account status?', 'I want to pay by QRPh', 'I have an internet concern'].map(prompt => <button key={prompt} disabled={busy} onClick={() => void ask(prompt)} className="rounded-full border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">{prompt}</button>)}</div>
      </div>
      <form onSubmit={event => { event.preventDefault(); void ask(draft); }} className="flex gap-2 border-t p-3"><input value={draft} onChange={event => setDraft(event.target.value)} maxLength={500} placeholder="Ask SolarNet…" className="min-w-0 flex-1 rounded-xl border px-3 py-2 text-sm" /><button disabled={busy || !draft.trim()} className="rounded-xl bg-blue-600 px-3 text-white disabled:opacity-50"><Send className="h-4 w-4" /></button></form>
    </section>}
    <button onClick={() => setOpen(current => !current)} className="ml-auto flex h-16 w-16 items-center justify-center overflow-hidden rounded-full border-2 border-cyan-300 bg-slate-950 shadow-xl" aria-label="Open SolarNet customer assistant"><img src="/solarnet-ai-chat.png" alt="" className="h-full w-full object-cover" /><MessageCircle className="sr-only" /></button>
  </div>;
}
