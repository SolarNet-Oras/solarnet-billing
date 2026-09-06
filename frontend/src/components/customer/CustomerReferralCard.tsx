import React, { useEffect, useState } from 'react';
import { Banknote, CheckCircle2, Gift, LoaderCircle, Mail, MapPin, Phone, Send, Sparkles, Users } from 'lucide-react';
import customerPortalService, { type CustomerReferral } from '../../services/customerPortalService';
import { formatPHP } from '../../lib/currency';

const EMPTY_FORM = { name: '', phone: '', email: '', address: '' };

const CustomerReferralCard: React.FC = () => {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [referrals, setReferrals] = useState<CustomerReferral[]>([]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const load = async () => {
    try { setReferrals((await customerPortalService.getReferrals()).data); } catch { /* The promotion remains usable if history is temporarily unavailable. */ }
  };

  useEffect(() => { void load(); }, []);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setMessage('');
    try {
      const result = await customerPortalService.submitReferral(form);
      setMessage(result.message); setForm(EMPTY_FORM); setOpen(false); await load();
    } catch (e: any) {
      setError(e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat().join(' ') || 'Could not submit this referral.');
    } finally { setBusy(false); }
  };

  const choose = async (referral: CustomerReferral, choice: 'cash' | 'billing_credit') => {
    setBusy(true); setError(''); setMessage('');
    try { const result = await customerPortalService.chooseReferralReward(referral.id, choice); setMessage(result.message); await load(); }
    catch (e: any) { setError(e.response?.data?.message || 'Could not save your reward choice.'); }
    finally { setBusy(false); }
  };

  return <section className="mb-8 overflow-hidden rounded-3xl border border-amber-200 bg-gradient-to-br from-amber-50 via-white to-sky-50 text-slate-900 shadow-lg shadow-amber-200/30 dark:border-amber-400/25 dark:from-slate-950 dark:via-blue-950 dark:to-slate-900 dark:text-white dark:shadow-amber-500/5">
    <div className="relative overflow-hidden p-5 sm:p-6"><div className="pointer-events-none absolute -right-12 -top-14 h-40 w-40 rounded-full bg-amber-300/25 blur-2xl dark:bg-amber-400/10" />
      <div className="relative flex flex-col justify-between gap-5 sm:flex-row sm:items-center"><div className="flex gap-4"><div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-400 to-orange-500 text-slate-950 shadow-lg"><Gift className="h-6 w-6" /></div><div><p className="text-xs font-bold uppercase tracking-[.18em] text-amber-700 dark:text-amber-300">SolarNet referral rewards</p><h2 className="mt-1 text-xl font-black text-slate-950 dark:text-white">Want to earn with SolarNet?</h2><p className="mt-2 max-w-2xl text-sm leading-6 text-slate-700 dark:text-slate-200">Refer a new client now. When your referral becomes a verified active SolarNet subscriber, you earn <b className="text-amber-700 dark:text-amber-300">₱200 per successful client</b>. What are you waiting for?</p></div></div><button type="button" onClick={() => { setOpen((value) => !value); setError(''); }} className="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-700 to-cyan-600 px-4 py-3 text-sm font-bold text-white shadow-md hover:from-blue-800 hover:to-cyan-700 dark:from-cyan-400 dark:to-blue-500 dark:text-slate-950"><Users className="h-4 w-4" />{open ? 'Close form' : 'Refer a client'}</button></div>
    </div>

    {open && <form onSubmit={submit} className="grid gap-4 border-t border-amber-200/70 bg-white/65 p-5 backdrop-blur dark:border-amber-400/15 dark:bg-slate-950/45 sm:grid-cols-2">
      <label className="text-sm font-semibold text-slate-800 dark:text-slate-100">Client name *<div className="relative mt-1"><Users className="absolute left-3 top-3 h-4 w-4 text-slate-400" /><input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-slate-950 outline-none focus:border-cyan-500 dark:border-slate-600 dark:bg-slate-900 dark:text-white" /></div></label>
      <label className="text-sm font-semibold text-slate-800 dark:text-slate-100">Phone number *<div className="relative mt-1"><Phone className="absolute left-3 top-3 h-4 w-4 text-slate-400" /><input required inputMode="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-slate-950 outline-none focus:border-cyan-500 dark:border-slate-600 dark:bg-slate-900 dark:text-white" /></div></label>
      <label className="text-sm font-semibold text-slate-800 dark:text-slate-100">Email (optional)<div className="relative mt-1"><Mail className="absolute left-3 top-3 h-4 w-4 text-slate-400" /><input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-slate-950 outline-none focus:border-cyan-500 dark:border-slate-600 dark:bg-slate-900 dark:text-white" /></div></label>
      <label className="text-sm font-semibold text-slate-800 dark:text-slate-100">Installation address *<div className="relative mt-1"><MapPin className="absolute left-3 top-3 h-4 w-4 text-slate-400" /><input required value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} className="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-slate-950 outline-none focus:border-cyan-500 dark:border-slate-600 dark:bg-slate-900 dark:text-white" /></div></label>
      <div className="sm:col-span-2"><p className="mb-3 text-xs leading-5 text-slate-600 dark:text-slate-300">Submitting a name does not immediately create a bonus. SolarNet verifies that this is a new client and that their subscription becomes active.</p><button disabled={busy} className="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-5 py-3 text-sm font-black text-slate-950 hover:bg-amber-400 disabled:opacity-60">{busy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}Submit referral</button></div>
    </form>}

    {(message || error) && <p className={`mx-5 mb-5 rounded-xl border px-4 py-3 text-sm font-medium ${error ? 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-400/25 dark:bg-rose-500/10 dark:text-rose-200' : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-400/25 dark:bg-emerald-500/10 dark:text-emerald-200'}`}>{error || message}</p>}

    {referrals.length > 0 && <div className="border-t border-amber-200/70 p-5 dark:border-amber-400/15"><h3 className="flex items-center gap-2 font-bold"><Sparkles className="h-4 w-4 text-amber-500" />Your referrals</h3><div className="mt-3 grid gap-3 lg:grid-cols-2">{referrals.map((referral) => <article key={referral.id} className="rounded-2xl border border-slate-200 bg-white/80 p-4 dark:border-slate-700 dark:bg-slate-900/75"><div className="flex items-start justify-between gap-3"><div><p className="font-bold text-slate-950 dark:text-white">{referral.name}</p><p className="mt-1 text-xs text-slate-600 dark:text-slate-300">Submitted {new Date(referral.created_at).toLocaleDateString('en-PH')}</p></div><span className={`rounded-full px-2.5 py-1 text-[10px] font-black uppercase ${referral.status === 'qualified' ? 'bg-amber-100 text-amber-900 dark:bg-amber-400/15 dark:text-amber-200' : referral.status === 'rewarded' ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-400/15 dark:text-emerald-200' : 'bg-sky-100 text-sky-900 dark:bg-sky-400/15 dark:text-sky-200'}`}>{referral.status.replaceAll('_', ' ')}</span></div>
          {referral.status === 'qualified' && !referral.reward_choice && <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-400/20 dark:bg-amber-400/10"><p className="text-sm font-bold text-amber-950 dark:text-amber-100"><CheckCircle2 className="mr-1 inline h-4 w-4" />Successful referral! Choose your {formatPHP(referral.reward_amount)} reward:</p><div className="mt-3 grid grid-cols-2 gap-2"><button disabled={busy} onClick={() => void choose(referral, 'cash')} className="rounded-lg border border-amber-400 bg-white px-3 py-2 text-xs font-bold text-amber-900 dark:bg-slate-900 dark:text-amber-100"><Banknote className="mr-1 inline h-4 w-4" />Claim cash</button><button disabled={busy} onClick={() => void choose(referral, 'billing_credit')} className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white"><Gift className="mr-1 inline h-4 w-4" />Future bill credit</button></div></div>}
          {referral.reward_choice && <p className="mt-3 text-xs leading-5 text-slate-700 dark:text-slate-200">Reward selected: <b>{referral.reward_choice === 'cash' ? '₱200 cash claim — bring a valid ID and account number to the SolarNet office.' : '₱200 future-bill credit added to your account.'}</b></p>}
        </article>)}</div></div>}
  </section>;
};

export default CustomerReferralCard;
