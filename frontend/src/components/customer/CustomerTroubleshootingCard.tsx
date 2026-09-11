import React, { useEffect, useRef, useState } from 'react';
import { AlertTriangle, CheckCircle2, MessageCircle, Send, ShieldCheck, X } from 'lucide-react';
import customerPortalService, { type CustomerTroubleshootingResponse } from '../../services/customerPortalService';

type ChatLine = { role: 'assistant' | 'customer'; content: string };
type Position = { x: number; y: number };

const BUTTON_SIZE = 64;
const SCREEN_GAP = 12;

const CustomerTroubleshootingCard: React.FC = () => {
  const [open, setOpen] = useState(false);
  const [showGreeting, setShowGreeting] = useState(true);
  const [position, setPosition] = useState<Position>({ x: 0, y: 0 });
  const [positionReady, setPositionReady] = useState(false);
  const [session, setSession] = useState<CustomerTroubleshootingResponse['session'] | null>(null);
  const [messages, setMessages] = useState<ChatLine[]>([]);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [ticketMessage, setTicketMessage] = useState('');
  const drag = useRef({ pointerId: -1, startX: 0, startY: 0, originX: 0, originY: 0, moved: false });

  const clampPosition = (x: number, y: number): Position => ({
    x: Math.max(SCREEN_GAP, Math.min(x, window.innerWidth - BUTTON_SIZE - SCREEN_GAP)),
    y: Math.max(SCREEN_GAP, Math.min(y, window.innerHeight - BUTTON_SIZE - SCREEN_GAP)),
  });

  useEffect(() => {
    let initial = { x: window.innerWidth - BUTTON_SIZE - 24, y: window.innerHeight - BUTTON_SIZE - 32 };
    try {
      const saved = window.localStorage.getItem('solarnet-customer-ai-position');
      if (saved) initial = JSON.parse(saved) as Position;
    } catch {
      // A corrupt browser preference should never prevent the assistant loading.
    }
    setPosition(clampPosition(initial.x, initial.y));
    setPositionReady(true);
    const keepVisible = () => setPosition((current) => clampPosition(current.x, current.y));
    window.addEventListener('resize', keepVisible);
    return () => window.removeEventListener('resize', keepVisible);
  }, []);

  const beginDrag = (event: React.PointerEvent<HTMLButtonElement>) => {
    event.currentTarget.setPointerCapture(event.pointerId);
    drag.current = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, originX: position.x, originY: position.y, moved: false };
  };

  const moveDrag = (event: React.PointerEvent<HTMLButtonElement>) => {
    if (drag.current.pointerId !== event.pointerId) return;
    const dx = event.clientX - drag.current.startX;
    const dy = event.clientY - drag.current.startY;
    if (Math.abs(dx) + Math.abs(dy) > 5) drag.current.moved = true;
    setPosition(clampPosition(drag.current.originX + dx, drag.current.originY + dy));
  };

  const finishDrag = (event: React.PointerEvent<HTMLButtonElement>) => {
    if (drag.current.pointerId !== event.pointerId) return;
    if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
    const finalPosition = clampPosition(drag.current.originX + event.clientX - drag.current.startX, drag.current.originY + event.clientY - drag.current.startY);
    setPosition(finalPosition);
    window.localStorage.setItem('solarnet-customer-ai-position', JSON.stringify(finalPosition));
    if (!drag.current.moved) {
      setOpen((current) => !current);
      setShowGreeting(false);
    }
    drag.current.pointerId = -1;
  };

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
    <div className="pointer-events-none fixed inset-0 z-[70]">
      {open && <section className="pointer-events-auto fixed bottom-24 right-3 flex max-h-[min(36rem,calc(100vh-7rem))] w-[min(25rem,calc(100vw-1.5rem))] flex-col overflow-hidden rounded-3xl border border-cyan-200/80 bg-gradient-to-b from-white via-sky-50/95 to-blue-50/95 text-slate-900 shadow-[0_24px_70px_rgba(2,132,199,0.28)] backdrop-blur-xl dark:border-cyan-500/30 dark:from-slate-950 dark:via-slate-950/98 dark:to-blue-950/95 dark:text-slate-100 dark:shadow-[0_24px_80px_rgba(6,182,212,0.2)] sm:right-5">
        <div className="flex items-start justify-between gap-3 border-b border-cyan-200/70 bg-gradient-to-r from-blue-600 via-cyan-600 to-sky-500 p-4 text-white dark:border-cyan-400/20 dark:from-blue-950 dark:via-cyan-950 dark:to-slate-950">
          <div className="flex gap-3">
            <div className="h-11 w-11 shrink-0 overflow-hidden rounded-full border-2 border-cyan-300 bg-slate-950 shadow-md"><img src="/solarnet-ai-chat.png" alt="SolarNet AI Chat" className="h-full w-full object-cover" /></div>
            <div><h3 className="font-bold text-white">No internet? Let’s check safely</h3><p className="mt-1 text-xs leading-5 text-cyan-50 dark:text-cyan-100/80">English / Filipino · Read-only network check</p></div>
          </div>
          <button type="button" onClick={() => setOpen(false)} aria-label="Minimize SolarNet assistant" className="rounded-xl p-1.5 text-cyan-50 hover:bg-white/15 hover:text-white"><X className="h-5 w-5" /></button>
        </div>

        {!session ? <div className="overflow-y-auto p-4 text-sm leading-6 text-slate-700 dark:text-slate-200">
          <p>SolarNet checks your account and synchronized network data without changing your router.</p>
          <div className="mt-3 flex gap-2 rounded-2xl border border-emerald-200/80 bg-emerald-50/90 p-3 text-emerald-950 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-100"><ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" /><span>We ask one simple question at a time and never ask you to factory-reset or change ISP settings.</span></div>
          <button type="button" onClick={() => void start()} disabled={busy} className="mt-4 w-full rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-500/20 hover:from-blue-700 hover:to-cyan-600 disabled:opacity-60 dark:from-cyan-500 dark:to-blue-500 dark:text-slate-950">{busy ? 'Starting…' : 'Start check'}</button>
        </div> : <div className="min-h-0 overflow-y-auto p-4">
          <div className="max-h-80 space-y-3 overflow-y-auto pr-1">
            {messages.map((line, index) => <div key={`${line.role}-${index}`} className={`flex ${line.role === 'customer' ? 'justify-end' : 'justify-start'}`}><div className={`max-w-[90%] rounded-2xl border px-4 py-3 text-sm leading-6 shadow-sm ${line.role === 'customer' ? 'border-blue-500 bg-gradient-to-br from-blue-600 to-cyan-600 text-white dark:border-cyan-400/40 dark:from-blue-600 dark:to-cyan-700' : 'border-slate-200/80 bg-white/90 text-slate-800 dark:border-slate-700 dark:bg-slate-800/90 dark:text-slate-100'}`}>{line.content}</div></div>)}
          </div>
          {session.diagnosis && <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-950 dark:border-amber-400/25 dark:bg-amber-400/10 dark:text-amber-100"><strong>Assessment:</strong> {session.diagnosis.confidence || 'UNKNOWN'}{session.diagnosis.cause ? ` — ${session.diagnosis.cause.replace(/_/g, ' ')}` : ''}</div>}
          {session.stage === 'ticket_confirmation' && session.status === 'active' && <button type="button" onClick={() => void escalate()} disabled={busy} className="mt-4 inline-flex items-center gap-2 rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-60"><AlertTriangle className="h-4 w-4" /> Create technical ticket</button>}
          {finished && <div className="mt-4 flex items-center gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-400/10 dark:text-emerald-200"><CheckCircle2 className="h-4 w-4" /> {ticketMessage || 'This check is complete. Start a new check if the problem returns.'}</div>}
          {!finished && <form onSubmit={send} className="mt-4 flex gap-2"><input value={draft} onChange={(event) => setDraft(event.target.value)} disabled={busy} placeholder="Type what you see / I-type ang nakikita ninyo..." className="min-w-0 flex-1 rounded-xl border border-slate-300 bg-white/90 px-4 py-2.5 text-sm text-slate-900 outline-none placeholder:text-slate-500 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-200 dark:border-slate-600 dark:bg-slate-900/90 dark:text-white dark:placeholder:text-slate-400 dark:focus:border-cyan-400 dark:focus:ring-cyan-900" /><button type="submit" disabled={busy || !draft.trim()} className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50 dark:bg-cyan-400 dark:text-slate-950 dark:hover:bg-cyan-300"><Send className="h-4 w-4" />Send</button></form>}
          {error && <p className="mt-3 rounded-xl bg-rose-50 p-2.5 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{error}</p>}
        </div>}
        {!session && <div className="flex items-center gap-2 border-t border-cyan-100/80 bg-white/40 px-4 py-3 text-xs text-slate-600 dark:border-cyan-400/15 dark:bg-slate-950/30 dark:text-slate-300"><MessageCircle className="h-4 w-4 shrink-0 text-cyan-600 dark:text-cyan-400" /> If unresolved, review the diagnostic summary before creating a ticket.</div>}
      </section>}

      {positionReady && showGreeting && !open && <button type="button" onClick={() => { setOpen(true); setShowGreeting(false); }} className="pointer-events-auto fixed max-w-[13rem] animate-bounce rounded-2xl rounded-br-sm border border-cyan-200 bg-gradient-to-br from-white to-cyan-50 px-4 py-3 text-left text-sm font-semibold text-slate-900 shadow-[0_12px_35px_rgba(14,165,233,0.3)] dark:border-cyan-400/30 dark:from-slate-900 dark:to-blue-950 dark:text-white" style={{ left: Math.max(8, Math.min(position.x - 154, window.innerWidth - 220)), top: Math.max(8, position.y - 72) }}>Hey, may I help you?<span className="mt-0.5 block text-xs font-normal text-slate-600 dark:text-cyan-100/75">Tap me for a safe internet check.</span></button>}

      {positionReady && <button
        type="button"
        aria-label={open ? 'Minimize SolarNet assistant' : 'Open SolarNet assistant'}
        title="Drag to move · Tap to open"
        onPointerDown={beginDrag}
        onPointerMove={moveDrag}
        onPointerUp={finishDrag}
        onPointerCancel={finishDrag}
        className="pointer-events-auto fixed h-16 w-16 touch-none select-none overflow-hidden rounded-full border-2 border-cyan-300 bg-slate-950 shadow-[0_10px_35px_rgba(14,165,233,0.45)] ring-4 ring-cyan-400/20 transition-transform hover:scale-105 active:scale-95"
        style={{ left: position.x, top: position.y }}
      >
        <img src="/solarnet-ai-chat.png" alt="" draggable={false} className="h-full w-full object-cover" />
        <span className="absolute right-0 top-0 h-3.5 w-3.5 rounded-full border-2 border-white bg-emerald-500" />
      </button>}
    </div>
  );
};

export default CustomerTroubleshootingCard;
