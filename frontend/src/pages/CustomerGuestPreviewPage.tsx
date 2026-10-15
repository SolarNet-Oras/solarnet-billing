import { ArrowLeft, Ban, CircleDollarSign, FileText, Headphones, LockKeyhole, ReceiptText, UserRound, Wifi } from 'lucide-react';
import { Link } from 'react-router-dom';

const lockedFeatures = [
  { icon: FileText, title: 'Billing statements', text: 'Customers can review current and previous statements and their due dates.' },
  { icon: ReceiptText, title: 'Payment history', text: 'Verified customers can see posted payments, references, and payment status.' },
  { icon: CircleDollarSign, title: 'Secure online payment', text: 'QRPh, GCash, and participating bank options appear for the customer’s specific open billing.' },
  { icon: Headphones, title: 'Account support', text: 'Customers can review connection details and request help for their own service.' },
  { icon: UserRound, title: 'Account information', text: 'Contact details and plan-change requests are available after account verification.' },
  { icon: Wifi, title: 'Connection status', text: 'Customers can view whether their own internet service is active or requires attention.' },
];

export default function CustomerGuestPreviewPage(): React.JSX.Element {
  return <main className="min-h-screen bg-slate-100 text-slate-950">
    <header className="border-b border-slate-800 bg-slate-950 text-white"><div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6"><Link to="/customer/login" className="inline-flex items-center gap-2 text-sm font-semibold text-cyan-200 hover:text-white"><ArrowLeft className="h-4 w-4" />Customer login</Link><div className="rounded-full border border-amber-300 bg-amber-300/15 px-3 py-1 text-xs font-bold uppercase tracking-wider text-amber-200">Guest preview</div></div></header>
    <div className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
      <section className="overflow-hidden rounded-3xl bg-gradient-to-br from-blue-800 to-cyan-700 p-6 text-white shadow-xl sm:p-8"><div className="grid gap-6 lg:grid-cols-[1fr_auto] lg:items-center"><div><p className="text-sm font-bold uppercase tracking-[.2em] text-cyan-100">Explore without an account</p><h1 className="mt-2 text-3xl font-black sm:text-4xl">See what SolarNet customers can access</h1><p className="mt-3 max-w-3xl text-base leading-7 text-blue-50">This preview uses sample information only. No real customer name, billing, payment, address, or connection data is loaded.</p></div><Link to="/signup" className="rounded-xl bg-amber-300 px-5 py-3 text-center font-black text-slate-950 shadow hover:bg-amber-200">Sign up free & apply</Link></div></section>
      <section className="rounded-2xl border-2 border-amber-300 bg-amber-50 p-5" role="alert"><div className="flex gap-3"><LockKeyhole className="mt-0.5 h-6 w-6 shrink-0 text-amber-800" /><div><h2 className="font-extrabold text-amber-950">Visitor access is view-only</h2><p className="mt-1 text-sm font-medium leading-6 text-amber-950">Sign up for free and apply for SolarNet service to receive an account. Guests cannot pay, open billing statements, submit account changes, create support requests, or view any real customer information.</p></div></div></section>
      <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><PreviewStat label="Connection" value="Sample active" /><PreviewStat label="Plan" value="Sample 50 Mbps" /><PreviewStat label="Open billing" value="₱0.00 sample" /><PreviewStat label="Next due" value="Not available" /></section>
      <section><div className="flex items-end justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-[.2em] text-blue-700">Customer portal preview</p><h2 className="mt-2 text-2xl font-black">Features available after verification</h2></div><LockKeyhole className="h-8 w-8 text-slate-500" /></div><div className="mt-5 grid gap-4 md:grid-cols-2 lg:grid-cols-3">{lockedFeatures.map(({ icon: Icon, title, text }) => <article key={title} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div className="flex items-start justify-between gap-3"><div className="rounded-xl bg-blue-100 p-2 text-blue-800"><Icon className="h-5 w-5" /></div><span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600"><Ban className="h-3 w-3" />Guest locked</span></div><h3 className="mt-4 text-lg font-extrabold">{title}</h3><p className="mt-2 text-sm leading-6 text-slate-700">{text}</p></article>)}</div></section>
      <section className="rounded-3xl bg-slate-950 p-6 text-center text-white sm:p-8"><h2 className="text-2xl font-black">Ready to become a SolarNet customer?</h2><p className="mx-auto mt-2 max-w-2xl text-sm leading-6 text-slate-300">Application is free. Select your preferred plan and submit your installation information for SolarNet review.</p><div className="mt-5 flex flex-wrap justify-center gap-3"><Link to="/signup" className="rounded-xl bg-cyan-400 px-5 py-3 font-black text-slate-950 hover:bg-cyan-300">Sign up free and apply</Link><Link to="/customer/login" className="rounded-xl border border-white/30 px-5 py-3 font-bold text-white hover:bg-white/10">I already have an account</Link></div></section>
    </div>
  </main>;
}

function PreviewStat({ label, value }: { label: string; value: string }): React.JSX.Element {
  return <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-xs font-bold uppercase tracking-wider text-slate-500">{label}</p><p className="mt-2 text-xl font-black text-slate-950">{value}</p><p className="mt-1 text-xs font-semibold text-blue-700">Demonstration only</p></article>;
}
