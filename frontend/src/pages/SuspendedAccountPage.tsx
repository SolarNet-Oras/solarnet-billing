import { AlertTriangle, ArrowRight, CircleHelp, FileText, ShieldCheck, WifiOff } from 'lucide-react';
import { Link } from 'react-router-dom';

/**
 * Public landing page for a router payment-reminder redirect. It deliberately
 * contains no account-specific data: customers sign in to see their own bill.
 */
const SuspendedAccountPage = () => (
  <main className="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6 sm:py-14">
    <section className="mx-auto max-w-xl overflow-hidden rounded-3xl border border-white/10 bg-slate-900 shadow-2xl shadow-black/35">
      <header className="flex items-center gap-3 border-b border-white/10 px-6 py-5">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500 font-bold text-white">S</div>
        <div><p className="font-semibold tracking-tight">SolarNet Internet</p><p className="text-xs text-slate-400">Customer billing portal</p></div>
      </header>

      <div className="p-6 sm:p-8">
        <div className="flex gap-4 rounded-2xl border border-rose-400/25 bg-rose-500/10 p-4">
          <AlertTriangle className="mt-0.5 h-6 w-6 shrink-0 text-rose-300" aria-hidden="true" />
          <div>
            <h1 className="text-xl font-semibold tracking-tight">Your service is temporarily restricted</h1>
            <p className="mt-2 text-sm leading-6 text-slate-300">Your SolarNet account requires attention. Sign in to review your invoice, outstanding balance, and payment instructions.</p>
          </div>
        </div>

        <div className="my-6 grid gap-3 text-sm sm:grid-cols-2">
          <div className="rounded-2xl bg-white/5 p-4"><WifiOff className="mb-3 h-5 w-5 text-amber-300" aria-hidden="true" /><p className="font-medium">Service restricted</p><p className="mt-1 text-slate-400">Full browsing is unavailable until payment is processed.</p></div>
          <div className="rounded-2xl bg-white/5 p-4"><ShieldCheck className="mb-3 h-5 w-5 text-sky-300" aria-hidden="true" /><p className="font-medium">Secure account access</p><p className="mt-1 text-slate-400">Your invoice details stay private after you sign in.</p></div>
        </div>

        <Link to="/customer/login" className="flex w-full items-center justify-center gap-2 rounded-xl bg-sky-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-sky-400">
          View my bill and payment options <ArrowRight className="h-4 w-4" aria-hidden="true" />
        </Link>
        <p className="mt-5 flex items-center justify-center gap-2 text-center text-xs text-slate-400"><FileText className="h-4 w-4" aria-hidden="true" />Already paid? Allow a few minutes for reconnection.</p>
        <p className="mt-3 flex items-center justify-center gap-2 text-center text-xs text-slate-400"><CircleHelp className="h-4 w-4" aria-hidden="true" />Need help? Contact SolarNet Support.</p>
      </div>
    </section>
  </main>
);

export default SuspendedAccountPage;
