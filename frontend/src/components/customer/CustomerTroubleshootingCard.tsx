import React, { useState } from 'react';
import { AlertTriangle, CheckCircle2, MessageCircle, Send, ShieldCheck, Wrench } from 'lucide-react';
import customerPortalService, { type CustomerTroubleshootingResponse } from '../../services/customerPortalService';

type ChatLine = { role: 'assistant' | 'customer'; content: string };

const CustomerTroubleshootingCard: React.FC = () => {
  const [session, setSession] = useState<CustomerTroubleshootingResponse['session'] | null>(null);
  const [messages, setMessages] = useState<ChatLine[]>([]);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [ticketMessage, setTicketMessage] = useState('');

  const appendAssistant = (response: CustomerTroubleshootingResponse) => {
    setSession(response.session);
    setMessages((current) => [...current, { role: 'assistant', content: response.assistant }]);
  };

  const start = async () => {
    setBusy(true); setError(''); setTicketMessage('');
    try {
      const response = await customerPortalService.startTroubleshooting();
      setMessages([{ role: 'assistant', content: response.assistant }]);
      setSession(response.session);
    } catch (e: any) {
      setError(e.response?.data?.message || 'The troubleshooting assistant is temporarily unavailable.');
    } finally { setBusy(false); }
  };

  const send = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!session || !draft.trim() || busy) return;
    const answer = draft.trim();
    setDraft(''); setBusy(true); setError('');
    setMessages((current) => [...current, { role: 'customer', content: answer }]);
    try {
      const response = await customerPortalService.sendTroubleshootingMessage(session.id, answer);
      appendAssistant(response);
    } catch (e: any) {
      setError(e.response?.data?.message || 'We could not save that answer. Please try again.');
    } finally { setBusy(false); }
  };

  const escalate = async () => {
    if (!session || busy) return;
    setBusy(true); setError('');
    try {
      const result = await customerPortalService.escalateTroubleshooting(session.id);
      setTicketMessage(`${result.message} Ticket ${result.ticket.ticket_number}.`);
      setSession((current) => current ? { ...current, status: 'escalated', stage: 'ticket_created' } : current);
    } catch (e: any) {
      setError(e.response?.data?.message || 'We could not create the support ticket.');
    } finally { setBusy(false); }
  };

  const finished = session?.status === 'completed' || session?.status === 'escalated';
  return (
    <section className="mb-8 overflow-hidden rounded-2xl border border-blue-100 bg-white shadow-sm">
      <div className="flex items-start justify-between gap-4 border-b border-blue-50 bg-gradient-to-r from-blue-50 to-sky-50 p-5">
        <div className="flex gap-3">
          <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white"><Wrench className="h-5 w-5" /></div>
          <div><h3 className="font-bold text-slate-900">No internet? Let’s check safely</h3><p className="mt-1 text-sm text-slate-600">You may reply in English or Filipino. SolarNet checks your account and synchronized network data without changing your router.</p></div>
        </div>
        {!session && <button type="button" onClick={() => void start()} disabled={busy} className="shrink-0 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Start check</button>}
      </div>
      {!session ? <div className="p-5 text-sm text-slate-600"><div className="flex gap-2"><ShieldCheck className="h-4 w-4 text-emerald-600" /> We ask one simple question at a time and never ask you to factory-reset or change ISP settings.</div></div> : <div className="p-5">
        <div className="max-h-80 space-y-3 overflow-y-auto pr-1">
          {messages.map((line, index) => <div key={`${line.role}-${index}`} className={`flex ${line.role === 'customer' ? 'justify-end' : 'justify-start'}`}><div className={`max-w-[90%] rounded-2xl px-4 py-3 text-sm leading-6 ${line.role === 'customer' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800'}`}>{line.content}</div></div>)}
        </div>
        {session.diagnosis && <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"><strong>Assessment:</strong> {session.diagnosis.confidence || 'UNKNOWN'}{session.diagnosis.cause ? ` — ${session.diagnosis.cause.replaceAll('_', ' ')}` : ''}</div>}
        {session.stage === 'ticket_confirmation' && session.status === 'active' && <button type="button" onClick={() => void escalate()} disabled={busy} className="mt-4 inline-flex items-center gap-2 rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-60"><AlertTriangle className="h-4 w-4" /> Create technical ticket</button>}
        {finished && <div className="mt-4 flex items-center gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"><CheckCircle2 className="h-4 w-4" /> {ticketMessage || 'This check is complete. Start a new check if the problem returns.'}</div>}
        {!finished && <form onSubmit={send} className="mt-4 flex gap-2"><input value={draft} onChange={(event) => setDraft(event.target.value)} disabled={busy} placeholder="Type what you see / I-type ang nakikita ninyo..." className="min-w-0 flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" /><button type="submit" disabled={busy || !draft.trim()} className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><Send className="h-4 w-4" />Send</button></form>}
        {error && <p className="mt-3 text-sm text-rose-700">{error}</p>}
      </div>}
      {!session && <div className="flex items-center gap-2 border-t border-slate-100 px-5 py-3 text-xs text-slate-500"><MessageCircle className="h-4 w-4" /> If the issue remains unresolved, you can review the diagnostic summary before a ticket is created.</div>}
    </section>
  );
};

export default CustomerTroubleshootingCard;
