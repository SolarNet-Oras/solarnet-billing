import { useState } from 'react';
import { MessageCircle, Send, ShieldCheck, X } from 'lucide-react';
import { Link } from 'react-router-dom';

type Line = { role: 'visitor' | 'assistant'; text: string };

const reply = (input: string): string => {
  const message = input.toLowerCase();
  if (/balance|account status|invoice|my account|suspend/.test(message)) return 'For privacy, account balances and status are available only after you verify your registered email or full name together with your SolarNet account number. Tap “Find my account” below.';
  if (/pay|qr|gcash|bank/.test(message)) return 'SolarNet accepts secure online payments through QRPh, GCash, and participating banks. Sign in to your account to display the QRPh code for your specific open billing. Confirmed payments post in real time.';
  if (/plan|price|mbps|speed/.test(message)) return 'SolarNet offers residential and business internet plans from 50 Mbps to 200 Mbps. Current prices are displayed in the Plans & prices section of this page.';
  if (/cctv/.test(message)) return 'SolarNet provides CCTV planning, camera installation, configuration, monitoring setup, and technical support for homes and businesses.';
  if (/solar/.test(message)) return 'SolarNet provides solar assessment, equipment installation, configuration, and after-installation support.';
  if (/internet|offline|slow|wifi|los|router/.test(message)) return 'For an account-specific internet concern, sign in first so the protected customer assistant can check only your own account. Never press the router or ONU reset button unless SolarNet support instructs you.';
  if (/hello|hi|hey|kumusta|joke|fun/.test(message)) return 'Hello! I can explain SolarNet plans, services, payments, and how to securely find your account. Router joke: it was feeling disconnected, so it joined the network. 🙂';
  return 'I can help with SolarNet internet plans, CCTV and solar services, QRPh/GCash/bank payments, technical-support guidance, and how to securely find your account.';
};

export default function PublicWebsiteAssistant(): React.JSX.Element {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState('');
  const [lines, setLines] = useState<Line[]>([{ role: 'assistant', text: 'Hi! Ask me about SolarNet services, plans, payments, or how to find your account.' }]);
  const send = (text: string): void => {
    const message = text.trim();
    if (!message) return;
    setDraft('');
    setLines(current => [...current, { role: 'visitor', text: message }, { role: 'assistant', text: reply(message) }]);
  };

  return <div className="fixed bottom-5 right-4 z-[90] sm:right-6">
    {open && <section className="mb-3 flex max-h-[75vh] w-[min(27rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-3xl border-2 border-cyan-300 bg-slate-950 text-white shadow-2xl">
      <header className="flex items-center justify-between bg-gradient-to-r from-blue-800 to-cyan-700 p-5 text-white"><div><h2 className="text-lg font-extrabold leading-tight">SolarNet Website Assistant</h2><p className="mt-1 text-sm font-medium text-white">General information only</p></div><button onClick={() => setOpen(false)} className="rounded-lg p-2 text-white hover:bg-white/15 focus:outline-none focus:ring-2 focus:ring-white" aria-label="Close assistant"><X className="h-5 w-5" /></button></header>
      <div className="overflow-y-auto bg-slate-950 p-4 sm:p-5"><div className="mb-4 flex gap-3 rounded-xl border-2 border-amber-300 bg-amber-100 p-4 text-base font-bold leading-6 text-slate-950"><ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-blue-800" />No customer information is available outside the verified customer portal.</div><div className="space-y-3">{lines.map((line, index) => <div key={index} className={`flex ${line.role === 'visitor' ? 'justify-end' : 'justify-start'}`}><p className={`max-w-[90%] rounded-2xl border-2 px-4 py-3 text-base font-semibold leading-6 ${line.role === 'visitor' ? 'border-blue-400 bg-blue-700 text-white' : 'border-slate-500 bg-slate-800 text-white'}`}>{line.text}</p></div>)}</div>
        <div className="mt-4 grid grid-cols-2 gap-2">{['Internet plans', 'How do I pay?', 'CCTV services', 'Solar installation'].map(prompt => <button key={prompt} onClick={() => send(prompt)} className="rounded-xl border-2 border-cyan-300 bg-cyan-50 px-3 py-2.5 text-sm font-extrabold text-slate-950 hover:bg-cyan-200 focus:outline-none focus:ring-2 focus:ring-white">{prompt}</button>)}</div>
        <Link to="/customer/login" className="mt-4 block rounded-xl border-2 border-amber-200 bg-amber-300 px-4 py-3 text-center text-base font-black text-slate-950 shadow-sm hover:bg-amber-200 focus:outline-none focus:ring-2 focus:ring-white">Find my account securely</Link>
      </div>
      <form onSubmit={event => { event.preventDefault(); send(draft); }} className="flex gap-2 border-t-2 border-slate-600 bg-slate-900 p-3"><input value={draft} onChange={event => setDraft(event.target.value)} maxLength={300} placeholder="Ask about SolarNet…" className="min-w-0 flex-1 rounded-xl border-2 border-slate-400 bg-white px-3 py-2.5 text-base font-semibold text-slate-950 placeholder:text-slate-600 focus:border-cyan-400 focus:outline-none" /><button disabled={!draft.trim()} aria-label="Send message" className="rounded-xl bg-cyan-400 px-4 text-slate-950 hover:bg-cyan-300 disabled:opacity-50"><Send className="h-5 w-5" /></button></form>
    </section>}
    <button onClick={() => setOpen(current => !current)} className="ml-auto flex h-16 w-16 items-center justify-center overflow-hidden rounded-full border-2 border-cyan-300 bg-slate-950 shadow-xl" aria-label="Open SolarNet website assistant"><img src="/solarnet-ai-chat.png" alt="" className="h-full w-full object-cover" /><MessageCircle className="sr-only" /></button>
  </div>;
}
