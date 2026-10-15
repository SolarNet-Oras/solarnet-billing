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
    {open && <section className="mb-3 flex max-h-[70vh] w-[min(24rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-3xl border border-cyan-200 bg-white text-slate-900 shadow-2xl">
      <header className="flex items-center justify-between bg-gradient-to-r from-blue-700 to-cyan-600 p-4 text-white"><div><h2 className="font-bold">SolarNet Website Assistant</h2><p className="text-xs text-cyan-50">General information only</p></div><button onClick={() => setOpen(false)} aria-label="Close assistant"><X className="h-5 w-5" /></button></header>
      <div className="overflow-y-auto p-4"><div className="mb-3 flex gap-2 rounded-xl bg-blue-50 p-3 text-xs text-blue-950"><ShieldCheck className="h-4 w-4 shrink-0" />No customer information is available outside the verified customer portal.</div><div className="space-y-2">{lines.map((line, index) => <div key={index} className={`flex ${line.role === 'visitor' ? 'justify-end' : 'justify-start'}`}><p className={`max-w-[88%] rounded-2xl px-3 py-2 text-sm leading-5 ${line.role === 'visitor' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-800'}`}>{line.text}</p></div>)}</div>
        <div className="mt-3 flex flex-wrap gap-2">{['Internet plans', 'How do I pay?', 'CCTV services', 'Solar installation'].map(prompt => <button key={prompt} onClick={() => send(prompt)} className="rounded-full border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50">{prompt}</button>)}</div>
        <Link to="/customer/login" className="mt-4 block rounded-xl bg-cyan-500 px-4 py-2.5 text-center text-sm font-bold text-slate-950">Find my account securely</Link>
      </div>
      <form onSubmit={event => { event.preventDefault(); send(draft); }} className="flex gap-2 border-t p-3"><input value={draft} onChange={event => setDraft(event.target.value)} maxLength={300} placeholder="Ask about SolarNet…" className="min-w-0 flex-1 rounded-xl border px-3 py-2 text-sm" /><button disabled={!draft.trim()} className="rounded-xl bg-blue-600 px-3 text-white disabled:opacity-50"><Send className="h-4 w-4" /></button></form>
    </section>}
    <button onClick={() => setOpen(current => !current)} className="ml-auto flex h-16 w-16 items-center justify-center overflow-hidden rounded-full border-2 border-cyan-300 bg-slate-950 shadow-xl" aria-label="Open SolarNet website assistant"><img src="/solarnet-ai-chat.png" alt="" className="h-full w-full object-cover" /><MessageCircle className="sr-only" /></button>
  </div>;
}
