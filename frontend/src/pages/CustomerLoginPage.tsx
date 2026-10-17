import React, { useEffect, useState } from 'react';
import { ArrowLeft, Hash, KeyRound, LogIn, ShieldCheck } from 'lucide-react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import customerPortalService from '../services/customerPortalService';
import FiberOpticBackground from '@/components/FiberOpticBackground';

type LoginMethod = 'account_number' | 'password';

const inputClass = 'mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-200';

const CustomerLoginPage: React.FC = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const [branding, setBranding] = useState({ name: 'Solarnet Internet', logo_url: '/solarnet-company-logo-192.png?v=20261015-official', email: '', facebook_url: '' });
  const [method, setMethod] = useState<LoginMethod>('account_number');
  const [form, setForm] = useState({ identity: '', account_number: '', password: '' });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => { void customerPortalService.getBranding().then(setBranding).catch(() => undefined); }, []);

  useEffect(() => {
    document.documentElement.classList.add('customer-access-active');
    return () => document.documentElement.classList.remove('customer-access-active');
  }, []);

  const selectMethod = (next: LoginMethod) => {
    setMethod(next);
    setError('');
  };

  const handleSubmit = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    setLoading(true);
    setError('');
    try {
      const result = method === 'account_number'
        ? await customerPortalService.login(form.identity, form.account_number)
        : await customerPortalService.login(form.identity, undefined, form.password);
      localStorage.setItem('customer_token', result.access_token);
      localStorage.setItem('customer_data', JSON.stringify(result.customer));
      const requested = new URLSearchParams(location.search).get('next');
      navigate(requested?.startsWith('/customer/') ? requested : '/customer/dashboard');
    } catch (caught: any) {
      setError(caught.response?.data?.message || (method === 'account_number'
        ? 'Account details did not match. Check both entries and try again.'
        : 'Email or password did not match. Check both entries and try again.'));
    } finally {
      setLoading(false);
    }
  };

  return <main className="relative min-h-screen overflow-hidden px-4 py-8 text-white">
    <FiberOpticBackground />
    <div className="relative z-10 mx-auto max-w-md">
      <Link to="/" className="mb-8 inline-flex items-center gap-2 text-sm text-sky-200 hover:text-white"><ArrowLeft className="h-4 w-4" />Back to SolarNet website</Link>
      <header className="mb-7 text-center">
        <img src={branding.logo_url || '/solarnet-mark.svg'} alt={branding.name} className="mx-auto mb-4 h-16 w-16 object-contain" />
        <p className="text-xs font-bold uppercase tracking-[.22em] text-cyan-300">Private customer access</p>
        <h1 className="mt-2 text-3xl font-bold">Find your SolarNet account</h1>
        <p className="mt-2 text-sm leading-6 text-slate-300">Use your registered identity with your account number, or sign in using your registered email and portal password.</p>
      </header>
      <section className="rounded-3xl border border-white/10 bg-white p-6 text-slate-950 shadow-2xl sm:p-8">
        {error && <div role="alert" className="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</div>}
        <div className="mb-5 rounded-2xl bg-slate-100 p-1.5">
          <div className="grid grid-cols-2 gap-1.5" role="tablist" aria-label="Customer login method">
            <button type="button" role="tab" aria-selected={method === 'account_number'} onClick={() => selectMethod('account_number')} className={`flex items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-sm font-bold transition ${method === 'account_number' ? 'bg-sky-700 text-white shadow' : 'text-slate-600 hover:bg-white'}`}><Hash className="h-4 w-4" />Account number</button>
            <button type="button" role="tab" aria-selected={method === 'password'} onClick={() => selectMethod('password')} className={`flex items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-sm font-bold transition ${method === 'password' ? 'bg-sky-700 text-white shadow' : 'text-slate-600 hover:bg-white'}`}><KeyRound className="h-4 w-4" />Password</button>
          </div>
          <p className="px-2 pb-1 pt-2 text-center text-xs font-medium text-slate-600">Account number is the recommended sign-in method.</p>
        </div>
        <form onSubmit={handleSubmit} className="space-y-4">
          <label className="block text-sm font-semibold">{method === 'account_number' ? 'Registered email or full name' : 'Registered email'}
            <input required autoFocus autoComplete="username" type={method === 'password' ? 'email' : 'text'} value={form.identity} onChange={event => setForm({ ...form, identity: event.target.value })} placeholder={method === 'account_number' ? 'juan@email.com or Juan Dela Cruz' : 'juan@email.com'} className={inputClass} />
          </label>
          {method === 'account_number' ? <label className="block text-sm font-semibold">Account number
            <input required inputMode="numeric" autoComplete="off" value={form.account_number} onChange={event => setForm({ ...form, account_number: event.target.value.trim() })} placeholder="Your SolarNet account number" className={inputClass} />
          </label> : <label className="block text-sm font-semibold">Portal password
            <input required type="password" autoComplete="current-password" value={form.password} onChange={event => setForm({ ...form, password: event.target.value })} placeholder="Your portal password" className={inputClass} />
          </label>}
          <button disabled={loading} className="flex w-full items-center justify-center gap-2 rounded-xl bg-sky-600 px-4 py-3 font-bold text-white hover:bg-sky-700 disabled:opacity-50">{loading ? <span className="h-5 w-5 animate-spin rounded-full border-2 border-white border-t-transparent" /> : <LogIn className="h-5 w-5" />}{loading ? 'Checking account…' : 'Open my account'}</button>
        </form>
        <div className="mt-5"><div className="mb-4 flex items-center gap-3 text-xs font-bold uppercase tracking-wider text-slate-400"><span className="h-px flex-1 bg-slate-200" />or<span className="h-px flex-1 bg-slate-200" /></div><Link to="/customer/preview" className="flex w-full items-center justify-center rounded-xl border-2 border-sky-700 bg-sky-50 px-4 py-3 font-bold text-sky-900 hover:bg-sky-100">Continue as visitor</Link><p className="mt-2 text-center text-xs font-medium text-slate-600">Explore a safe sample of the customer portal without signing up.</p></div>
        <div className="mt-5 space-y-2 rounded-xl bg-slate-50 p-4 text-xs leading-5 text-slate-600"><p className="flex gap-2"><ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />Your selected credentials must exactly match SolarNet records.</p><p className="flex gap-2"><KeyRound className="mt-0.5 h-4 w-4 shrink-0 text-sky-600" />Your invoices, payments, address, and connection details remain hidden until verification succeeds.</p></div>
        <p className="mt-5 text-center text-sm text-slate-600">Need a new connection? <Link to="/signup" className="font-semibold text-sky-700 hover:underline">Apply for service</Link></p>
      </section>
    </div>
  </main>;
};

export default CustomerLoginPage;
