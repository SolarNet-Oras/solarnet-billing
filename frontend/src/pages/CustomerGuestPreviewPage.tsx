import { useState, type ReactNode } from 'react';
import { AlertCircle, ArrowLeft, CircleDollarSign, CreditCard, FileText, Headphones, Home, Info, LockKeyhole, MapPin, ReceiptText, UserRound, Wifi } from 'lucide-react';
import { Link } from 'react-router-dom';

type PreviewView = 'dashboard' | 'billing' | 'profile' | 'support';

const navItems: Array<{ id: PreviewView; label: string; icon: typeof Home }> = [
  { id: 'dashboard', label: 'Dashboard', icon: Home },
  { id: 'billing', label: 'Billing', icon: ReceiptText },
  { id: 'profile', label: 'Profile', icon: UserRound },
  { id: 'support', label: 'Support', icon: Headphones },
];

export default function CustomerGuestPreviewPage(): React.JSX.Element {
  const [view, setView] = useState<PreviewView>('dashboard');
  const [notice, setNotice] = useState('');

  const demonstrate = (action: string): void => {
    setNotice(`${action} is disabled in guest preview. Verify an existing account or sign up free to use this feature.`);
    window.setTimeout(() => setNotice(''), 5000);
  };

  return <main className="min-h-screen bg-slate-50 text-slate-950 dark:bg-slate-950 dark:text-slate-50 dark:[&_.bg-white]:bg-slate-900 dark:[&_.bg-gray-50]:bg-slate-800 dark:[&_.bg-gray-100]:bg-slate-800 dark:[&_.border-gray-200]:border-slate-700 dark:[&_.border-gray-300]:border-slate-600 dark:[&_.text-gray-950]:text-white dark:[&_.text-gray-700]:text-slate-200 dark:[&_.text-gray-600]:text-slate-300 dark:[&_.text-blue-800]:text-cyan-300 dark:[&_.text-blue-700]:text-cyan-300 dark:[&_input]:border-slate-600 dark:[&_input]:bg-slate-950 dark:[&_input]:text-white dark:[&_textarea]:border-slate-600 dark:[&_textarea]:bg-slate-950 dark:[&_textarea]:text-white dark:[&_select]:border-slate-600 dark:[&_select]:bg-slate-950 dark:[&_select]:text-white">
    <header className="border-b border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
      <div className="mx-auto flex min-h-16 max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
        <div className="flex items-center gap-3"><img src="/solarnet-company-logo-192.png?v=20261015-official" alt="SolarNet" className="h-10 w-10 object-contain" /><div><h1 className="text-xl font-black text-gray-950">Solarnet Internet</h1><p className="text-xs font-semibold text-gray-600">Customer Portal · Guest demonstration</p></div></div>
        <div className="flex items-center gap-2"><span className="rounded-full border border-amber-400 bg-amber-100 px-3 py-1 text-xs font-black text-amber-950">SAMPLE DATA</span><Link to="/customer/login" className="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-blue-800 hover:bg-blue-50"><ArrowLeft className="h-4 w-4" />Exit preview</Link></div>
      </div>
    </header>

    <div className="border-b border-blue-900 bg-blue-950 text-white"><div className="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4 sm:px-6 lg:px-8">{navItems.map(({ id, label, icon: Icon }) => <button key={id} onClick={() => setView(id)} className={`inline-flex shrink-0 items-center gap-2 border-b-4 px-4 py-3 text-sm font-extrabold ${view === id ? 'border-cyan-300 bg-white/10 text-white' : 'border-transparent text-blue-100 hover:bg-white/10'}`}><Icon className="h-4 w-4" />{label}</button>)}</div></div>

    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
      <section className="mb-6 rounded-2xl border-2 border-amber-400 bg-amber-100 p-4 text-amber-950 shadow-sm"><div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"><div className="flex gap-3"><LockKeyhole className="mt-0.5 h-6 w-6 shrink-0" /><div><h2 className="font-black">Visitor preview — no real customer information</h2><p className="mt-1 text-sm font-semibold leading-6">All names, balances, invoices, payments, and connection details below are illustrations. Guest actions never reach SolarNet records.</p></div></div><Link to="/signup" className="shrink-0 rounded-xl bg-blue-800 px-5 py-3 text-center font-black text-white hover:bg-blue-900">Sign up free & apply</Link></div></section>

      {notice && <div role="alert" className="mb-6 rounded-xl border-2 border-red-500 bg-red-50 p-4 text-sm font-black text-red-800 shadow-sm dark:border-red-400 dark:bg-red-950 dark:text-red-200">{notice}</div>}

      {view === 'dashboard' && <DashboardPreview onDemo={demonstrate} onNavigate={setView} />}
      {view === 'billing' && <BillingPreview onDemo={demonstrate} />}
      {view === 'profile' && <ProfilePreview onDemo={demonstrate} />}
      {view === 'support' && <SupportPreview onDemo={demonstrate} />}
    </div>
  </main>;
}

function DashboardPreview({ onDemo, onNavigate }: { onDemo: (action: string) => void; onNavigate: (view: PreviewView) => void }): React.JSX.Element {
  return <><div className="mb-7"><p className="text-sm font-bold text-blue-800">Demonstration account</p><h2 className="mt-1 text-3xl font-black text-gray-950">Welcome, Sample Customer!</h2><p className="mt-1 font-medium text-gray-700">Account: DEMO-000000</p></div>
    <section className="mb-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><Stat icon={FileText} label="Total billing statements" value="3" color="blue" /><Stat icon={AlertCircle} label="Open billing" value="₱800.00" color="amber" /><Stat icon={CreditCard} label="Advance credit" value="₱0.00" color="emerald" /><Stat icon={CircleDollarSign} label="Last payment" value="₱800.00" color="violet" /></section>
    <section className="mb-7 grid gap-5 lg:grid-cols-2"><Card title="Service Plan" icon={Wifi}><Detail label="Plan name" value="50 MBPS Residential" /><Detail label="Download speed" value="50 Mbps" /><Detail label="Upload speed" value="50 Mbps" /><Detail label="Monthly fee" value="₱800.00" /></Card><Card title="Account Details" icon={UserRound}><Detail label="Status" value="SAMPLE ACTIVE" badge /><Detail label="Email" value="sample@example.com" /><Detail label="Phone" value="09XX XXX XXXX" /><Detail label="Service area" value="Eastern Samar" /></Card></section>
    <section className="rounded-xl border-2 border-gray-300 bg-white p-6 shadow-sm"><h3 className="text-lg font-black text-gray-950">Quick Actions</h3><div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><Action icon={FileText} title="View billing" text="See sample statements" onClick={() => onNavigate('billing')} /><Action icon={CreditCard} title="Payment history" text="See sample payments" onClick={() => onNavigate('billing')} /><Action icon={UserRound} title="Update profile" text="Preview account details" onClick={() => onNavigate('profile')} /><Action icon={Info} title="Pay online" text="Preview secure payment" onClick={() => onDemo('Online payment')} /></div></section>
  </>;
}

function BillingPreview({ onDemo }: { onDemo: (action: string) => void }): React.JSX.Element {
  return <><Header eyebrow="Billing" title="Billing statements and payments" text="This table demonstrates the billing information available to a verified customer." /><section className="overflow-hidden rounded-xl border-2 border-gray-300 bg-white shadow-sm"><div className="border-b bg-gray-50 px-5 py-4"><h3 className="font-black text-gray-950">Sample billing statements</h3></div><div className="overflow-x-auto"><table className="w-full min-w-[720px] text-left text-sm"><thead className="bg-blue-950 text-white"><tr><th className="px-5 py-3">Statement</th><th className="px-5 py-3">Issue date</th><th className="px-5 py-3">Due date</th><th className="px-5 py-3">Amount</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Action</th></tr></thead><tbody className="divide-y divide-gray-200 text-gray-950"><tr><td className="px-5 py-4 font-bold">SLR-DEMO-0003</td><td className="px-5 py-4">Oct 6, 2026</td><td className="px-5 py-4">Oct 13, 2026</td><td className="px-5 py-4 font-black">₱800.00</td><td className="px-5 py-4"><span className="rounded-full bg-amber-100 px-2 py-1 font-bold text-amber-900">OPEN</span></td><td className="px-5 py-4"><button onClick={() => onDemo('Pay Now')} className="rounded-lg bg-blue-700 px-3 py-2 font-bold text-white">Pay now</button></td></tr><tr><td className="px-5 py-4 font-bold">SLR-DEMO-0002</td><td className="px-5 py-4">Sep 6, 2026</td><td className="px-5 py-4">Sep 13, 2026</td><td className="px-5 py-4 font-black">₱800.00</td><td className="px-5 py-4"><span className="rounded-full bg-emerald-100 px-2 py-1 font-bold text-emerald-900">PAID</span></td><td className="px-5 py-4"><button onClick={() => onDemo('Statement download')} className="font-bold text-blue-800 hover:underline">View statement</button></td></tr></tbody></table></div></section>
    <section className="mt-6 rounded-xl border-2 border-gray-300 bg-white p-5 shadow-sm"><h3 className="font-black text-gray-950">Sample payment history</h3><div className="mt-4 flex flex-col justify-between gap-3 rounded-xl bg-gray-100 p-4 sm:flex-row sm:items-center"><div><p className="font-black text-gray-950">PAY-DEMO-0001</p><p className="text-sm font-medium text-gray-700">QRPh · Sep 10, 2026 · Demonstration only</p></div><p className="text-xl font-black text-emerald-800">₱800.00</p></div></section></>;
}

function ProfilePreview({ onDemo }: { onDemo: (action: string) => void }): React.JSX.Element {
  return <><Header eyebrow="Profile" title="Account information" text="Verified customers can review and request updates to their own contact and service information." /><section className="grid gap-5 lg:grid-cols-2"><Card title="Contact information" icon={UserRound}><DemoInput label="Full name" value="Sample Customer" /><DemoInput label="Email" value="sample@example.com" /><DemoInput label="Phone" value="09XX XXX XXXX" /><button onClick={() => onDemo('Profile update')} className="mt-4 w-full rounded-xl bg-blue-700 px-4 py-3 font-black text-white">Save sample changes</button></Card><Card title="Installation information" icon={MapPin}><DemoInput label="Service address" value="Sample address, Eastern Samar" /><DemoInput label="Preferred plan" value="50 MBPS Residential" /><button onClick={() => onDemo('Plan change request')} className="mt-4 w-full rounded-xl border-2 border-blue-700 px-4 py-3 font-black text-blue-800">Request sample plan change</button></Card></section></>;
}

function SupportPreview({ onDemo }: { onDemo: (action: string) => void }): React.JSX.Element {
  return <><Header eyebrow="Support" title="Internet and account assistance" text="A verified customer can submit a concern tied only to their own SolarNet account." /><section className="mx-auto max-w-3xl rounded-xl border-2 border-gray-300 bg-white p-6 shadow-sm"><label className="block text-sm font-black text-gray-950">Concern type<select className="mt-2 w-full rounded-xl border-2 border-gray-300 bg-white px-4 py-3 text-gray-950"><option>Internet connection concern</option><option>Billing question</option><option>Account information update</option></select></label><label className="mt-4 block text-sm font-black text-gray-950">Message<textarea rows={5} defaultValue="This is a sample message. No support request will be created." className="mt-2 w-full rounded-xl border-2 border-gray-300 px-4 py-3 text-gray-950" /></label><button onClick={() => onDemo('Support request')} className="mt-4 w-full rounded-xl bg-blue-700 px-4 py-3 font-black text-white">Submit sample concern</button></section></>;
}

function Header({ eyebrow, title, text }: { eyebrow: string; title: string; text: string }): React.JSX.Element { return <div className="mb-7"><p className="text-sm font-black uppercase tracking-wider text-blue-800">{eyebrow} · Guest preview</p><h2 className="mt-2 text-3xl font-black text-gray-950">{title}</h2><p className="mt-2 max-w-3xl text-base font-medium leading-7 text-gray-700">{text}</p></div>; }
function Card({ title, icon: Icon, children }: { title: string; icon: typeof Wifi; children: ReactNode }): React.JSX.Element { return <section className="rounded-xl border-2 border-gray-300 bg-white p-6 shadow-sm"><h3 className="mb-4 flex items-center gap-2 text-lg font-black text-gray-950"><Icon className="h-5 w-5 text-blue-700" />{title}</h3><div className="space-y-2">{children}</div></section>; }
function Detail({ label, value, badge = false }: { label: string; value: string; badge?: boolean }): React.JSX.Element { return <div className="flex items-center justify-between gap-4 border-b border-gray-200 py-3 last:border-0"><span className="text-sm font-semibold text-gray-700">{label}</span><span className={badge ? 'rounded-full bg-emerald-100 px-2 py-1 text-sm font-black text-emerald-900' : 'text-right text-sm font-black text-gray-950'}>{value}</span></div>; }
function Stat({ icon: Icon, label, value, color }: { icon: typeof FileText; label: string; value: string; color: string }): React.JSX.Element { const colors: Record<string, string> = { blue: 'bg-blue-100 text-blue-800', amber: 'bg-amber-100 text-amber-900', emerald: 'bg-emerald-100 text-emerald-900', violet: 'bg-violet-100 text-violet-900' }; return <article className="rounded-xl border-2 border-gray-300 bg-white p-5 shadow-sm"><div className="flex items-center justify-between"><div className={`rounded-xl p-3 ${colors[color]}`}><Icon className="h-6 w-6" /></div><span className="text-2xl font-black text-gray-950">{value}</span></div><p className="mt-4 text-sm font-bold text-gray-700">{label}</p><p className="mt-1 text-xs font-black text-blue-800">SAMPLE</p></article>; }
function Action({ icon: Icon, title, text, onClick }: { icon: typeof FileText; title: string; text: string; onClick: () => void }): React.JSX.Element { return <button onClick={onClick} className="flex items-center gap-3 rounded-xl border-2 border-gray-300 p-4 text-left hover:border-blue-600 hover:bg-blue-50"><Icon className="h-6 w-6 shrink-0 text-blue-700" /><span><strong className="block text-gray-950">{title}</strong><span className="text-sm font-medium text-gray-600">{text}</span></span></button>; }
function DemoInput({ label, value }: { label: string; value: string }): React.JSX.Element { return <label className="block text-sm font-black text-gray-950">{label}<input defaultValue={value} className="mt-2 w-full rounded-xl border-2 border-gray-300 bg-white px-4 py-3 font-medium text-gray-950" /></label>; }
