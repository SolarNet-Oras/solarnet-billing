import { useEffect, useState } from 'react';
import { useLocation, useParams } from 'react-router-dom';
import { CheckCircle2, CreditCard, Loader2, ShieldCheck, Smartphone } from 'lucide-react';
import api from '@/services/api';
import { attachPaymongoQrPh } from '@/services/paymongoQrService';
import { formatPHP } from '@/lib/currency';
import FiberOpticBackground from '@/components/FiberOpticBackground';

type Summary = { invoice: { invoice_number: string; issue_date: string; due_date: string; total: number; paid_amount: number; balance: number; status: string }; customer: { full_name: string; account_number_masked: string } };
type QrPayment = { checkout_id: string; payment_intent_id: string; client_key: string; public_key: string; base_url?: string; qr_image_url?: string | null; invoice_number: string; amount: number };

const formatDueDate = (value: string): string => new Intl.DateTimeFormat('en-PH', {
  timeZone: 'Asia/Manila', year: 'numeric', month: 'long', day: 'numeric',
}).format(new Date(value));

export default function InvoiceQuickPayPage(): React.JSX.Element {
  const { token = '' } = useParams();
  const location = useLocation();
  const base = `/customer-portal/pay/${encodeURIComponent(token)}`;
  const [summary, setSummary] = useState<Summary | null>(null);
  const [qr, setQr] = useState<QrPayment | null>(null);
  const [busy, setBusy] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [showMethods, setShowMethods] = useState(false);

  const load = async () => {
    const response = await api.get(base);
    setSummary(response.data.data);
  };

  useEffect(() => {
    void (async () => {
      try {
        const query = new URLSearchParams(location.search);
        if (query.get('payment') === 'success') {
          const checkoutId = query.get('checkout');
          const result = checkoutId
            ? await api.post(`${base}/online-banking/${encodeURIComponent(checkoutId)}/reconcile`)
            : await api.post(`${base}/gcash/reconcile`);
          setMessage(result.data.paid ? 'Payment confirmed and automatically recorded.' : 'PayMongo is still confirming the payment. Please refresh shortly.');
        }
        await load();
      } catch (requestError: any) {
        setError(requestError.response?.data?.message || 'This secure invoice payment link is unavailable.');
      }
    })();
  }, [token, location.search]);

  const onlineCheckout = async () => {
    setBusy('checkout'); setError('');
    try {
      const response = await api.post(`${base}/gcash`);
      window.location.assign(response.data.data.checkout_url);
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not open the secure online checkout.'); setBusy(''); }
  };

  const qrPh = async () => {
    setBusy('qr'); setError('');
    try {
      let payment = (await api.post(`${base}/qr-ph`)).data.data as QrPayment;
      if (!payment.qr_image_url) {
        const attachment = await attachPaymongoQrPh({ publicKey: payment.public_key, baseUrl: payment.base_url, paymentIntentId: payment.payment_intent_id, clientKey: payment.client_key });
        payment = (await api.post(`${base}/qr-ph/${payment.checkout_id}/attach`, attachment)).data.data;
      }
      setShowMethods(false);
      setQr(payment);
    } catch (requestError: any) { setError(requestError.response?.data?.message || requestError.message || 'Could not prepare QR Ph.'); }
    finally { setBusy(''); }
  };

  useEffect(() => {
    if (!qr) return;
    const timer = window.setInterval(async () => {
      try {
        const response = await api.post(`${base}/qr-ph/${qr.checkout_id}/reconcile`);
        if (response.data.paid) {
          setQr(null); setMessage('QR Ph payment confirmed and automatically recorded.'); await load();
        }
      } catch { /* PayMongo webhook remains authoritative. */ }
    }, 5000);
    return () => window.clearInterval(timer);
  }, [qr?.checkout_id, token]);

  return <main className="relative grid min-h-screen place-items-center overflow-hidden p-4 text-slate-900 sm:p-8">
    <FiberOpticBackground />
    <section className="invoice-quick-pay-card relative z-10 w-full max-w-lg overflow-hidden rounded-3xl border-2 border-white bg-white p-6 text-slate-950 shadow-[0_30px_90px_rgba(0,0,0,.55)] [color-scheme:light] sm:p-8">
      <div className="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-cyan-400 via-blue-600 to-emerald-400" />
      <div className="flex items-center gap-4"><img src="/solarnet-company-logo-192.png" alt="SolarNet official logo" className="h-16 w-16 shrink-0 rounded-full border-2 border-slate-200 object-cover shadow-md" /><div className="min-w-0"><div className="flex items-center gap-2 text-blue-800"><ShieldCheck className="h-4 w-4" /><p className="text-xs font-black uppercase tracking-[0.16em]">SolarNet secure payment</p></div><h1 className="mt-1 text-2xl font-black tracking-tight text-slate-950">View and pay invoice</h1><p className="mt-1 text-sm font-semibold text-slate-700">Protected billing access for one SolarNet account</p></div></div>
      {error && <p className="mt-5 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {message && <p className="mt-5 flex gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"><CheckCircle2 className="h-5 w-5 shrink-0" />{message}</p>}
      {!summary && !error && <div className="grid place-items-center py-16 text-blue-600"><Loader2 className="h-8 w-8 animate-spin" /></div>}
      {summary && <>
        <div className="invoice-quick-pay-summary mt-6 rounded-2xl border-2 border-sky-200 bg-sky-50 p-5 shadow-sm"><p className="text-lg font-black text-slate-950">{summary.customer.full_name}</p><p className="mt-0.5 text-sm font-semibold text-slate-700">Account {summary.customer.account_number_masked} · {summary.invoice.invoice_number}</p><dl className="mt-5 grid grid-cols-2 gap-3 text-sm"><div className="invoice-quick-pay-stat rounded-xl bg-white p-3"><dt className="font-semibold text-slate-600">Due date</dt><dd className="mt-1 font-black text-slate-950">{formatDueDate(summary.invoice.due_date)}</dd></div><div className="invoice-quick-pay-stat rounded-xl bg-white p-3"><dt className="font-semibold text-slate-600">Status</dt><dd className="mt-1 font-black capitalize text-slate-950">{summary.invoice.status}</dd></div></dl><div className="mt-4 border-t-2 border-sky-200 pt-4"><p className="text-sm font-bold text-slate-700">Amount due</p><p className="mt-1 text-4xl font-black text-blue-800">{formatPHP(summary.invoice.balance)}</p></div></div>
        {Number(summary.invoice.balance) > 0 ? (!showMethods
          ? <button onClick={() => setShowMethods(true)} className="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl border-2 border-blue-800 bg-blue-700 px-4 py-4 text-lg font-black text-white shadow-lg transition hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-200"><CreditCard className="h-5 w-5" />Pay now</button>
          : <section className="invoice-quick-pay-methods mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4"><div className="mb-4 flex items-center justify-between gap-3"><div><h2 className="font-black text-slate-950">Select payment method</h2><p className="text-xs text-slate-500">Choose one secure PayMongo payment option.</p></div><button onClick={() => setShowMethods(false)} disabled={!!busy} className="invoice-quick-pay-cancel text-sm font-bold text-slate-600 hover:text-slate-950 disabled:opacity-50">Cancel</button></div><div className="grid gap-3"><button onClick={() => void qrPh()} disabled={!!busy} className="invoice-quick-pay-qr inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 font-bold text-white disabled:opacity-60"><Smartphone className="h-5 w-5" />{busy === 'qr' ? 'Preparing QR Ph…' : 'QRPh — scan with GCash or bank'}</button><button onClick={() => void onlineCheckout()} disabled={!!busy} className="invoice-quick-pay-hosted inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-bold text-white disabled:opacity-60"><CreditCard className="h-5 w-5" />{busy === 'checkout' ? 'Opening secure checkout…' : 'Card, GCash, Maya, BPI, UBP & other methods'}</button></div><p className="invoice-quick-pay-availability mt-3 text-center text-xs font-medium text-slate-500">Only payment methods activated by PayMongo are available.</p></section>)
          : <p className="mt-5 rounded-xl bg-emerald-50 p-4 text-center font-semibold text-emerald-800">This invoice is fully paid.</p>}
      </>}
      <p className="invoice-quick-pay-note mt-5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-center text-sm font-medium leading-6 text-slate-700">No portal login is required. This signed link opens only the invoice shown above. Payment is recorded only after PayMongo confirms it.</p>
    </section>
    {qr && <div className="fixed inset-0 z-[100] grid place-items-center bg-slate-950/80 p-4"><section role="dialog" aria-modal="true" aria-labelledby="qr-ph-title" className="w-full max-w-sm rounded-2xl bg-white p-6 text-center text-slate-950 shadow-2xl [color-scheme:light]"><h2 id="qr-ph-title" className="text-xl font-bold text-slate-950">Scan QR Ph</h2><p className="mt-1 text-sm font-medium text-slate-600">{qr.invoice_number} · {formatPHP(qr.amount)}</p><img src={qr.qr_image_url || ''} className="mx-auto my-5 h-64 w-64 rounded-xl border border-slate-300 bg-white p-2" alt={`QR Ph payment code for ${qr.invoice_number}`} /><p className="text-sm font-semibold text-emerald-700">Waiting for payment confirmation…</p><button onClick={() => setQr(null)} className="mt-5 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">Close</button></section></div>}
  </main>;
}
