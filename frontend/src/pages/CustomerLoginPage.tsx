import React, { useEffect, useState } from 'react';
import { ArrowLeft, KeyRound, LogIn, ShieldCheck } from 'lucide-react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import customerPortalService from '../services/customerPortalService';

const CustomerLoginPage: React.FC = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const [branding, setBranding] = useState({ name: 'Solarnet Internet', logo_url: '', email: '', facebook_url: '' });
  const [form, setForm] = useState({ identity: '', account_number: '' });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => { void customerPortalService.getBranding().then(setBranding).catch(() => undefined); }, []);

  const handleSubmit = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    setLoading(true);
    setError('');
    try {
      const result = await customerPortalService.login(form.identity, form.account_number);
      localStorage.setItem('customer_token', result.access_token);
      localStorage.setItem('customer_data', JSON.stringify(result.customer));
      const requested = new URLSearchParams(location.search).get('next');
      navigate(requested?.startsWith('/customer/') ? requested : '/customer/dashboard');
    } catch (caught: any) {
      setError(caught.response?.data?.message || 'Account details did not match. Check both entries and try again.');
    } finally {
      setLoading(false);
    }
  };

  return <main className="min-h-screen bg-gradient-to-br from-slate-950 via-sky-950 to-slate-950 px-4 py-8 text-white"><div className="mx-auto max-w-md"><Link to="/" className="mb-8 inline-flex items-center gap-2 text-sm text-sky-200 hover:text-white"><ArrowLeft className="h-4 w-4"/>Back to SolarNet website</Link><header className="mb-7 text-center"><img src={branding.logo_url || '/solarnet-mark.svg'} alt={branding.name} className="mx-auto mb-4 h-16 w-16 object-contain"/><p className="text-xs font-bold uppercase tracking-[.22em] text-cyan-300">Private customer access</p><h1 className="mt-2 text-3xl font-bold">Find your SolarNet account</h1><p className="mt-2 text-sm leading-6 text-slate-300">Enter the email address or full name registered on your account together with your account number.</p></header><section className="rounded-3xl border border-white/10 bg-white p-6 text-slate-950 shadow-2xl sm:p-8">{error && <div role="alert" className="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{error}</div>}<form onSubmit={handleSubmit} className="space-y-4"><label className="block text-sm font-semibold">Registered email or full name<input required autoFocus autoComplete="username" value={form.identity} onChange={event => setForm({ ...form, identity: event.target.value })} placeholder="juan@email.com or Juan Dela Cruz" className="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-200"/></label><label className="block text-sm font-semibold">Account number<input required inputMode="numeric" autoComplete="off" value={form.account_number} onChange={event => setForm({ ...form, account_number: event.target.value.trim() })} placeholder="Your SolarNet account number" className="mt-1.5 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-200"/></label><button disabled={loading} className="flex w-full items-center justify-center gap-2 rounded-xl bg-sky-600 px-4 py-3 font-bold text-white hover:bg-sky-700 disabled:opacity-50">{loading ? <span className="h-5 w-5 animate-spin rounded-full border-2 border-white border-t-transparent"/> : <LogIn className="h-5 w-5"/>}{loading ? 'Checking account…' : 'Open my account'}</button></form><div className="mt-5 space-y-2 rounded-xl bg-slate-50 p-4 text-xs leading-5 text-slate-600"><p className="flex gap-2"><ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>Both details must exactly match SolarNet records. Other customers cannot be searched or displayed.</p><p className="flex gap-2"><KeyRound className="mt-0.5 h-4 w-4 shrink-0 text-sky-600"/>Your invoices, payments, address, and connection details remain hidden until verification succeeds.</p></div><p className="mt-5 text-center text-sm text-slate-600">Need a new connection? <Link to="/signup" className="font-semibold text-sky-700 hover:underline">Apply for service</Link></p></section></div></main>;
};

export default CustomerLoginPage;
