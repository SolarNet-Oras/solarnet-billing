import { useEffect, useState } from 'react';
import { useLocation, useParams } from 'react-router-dom';
import { CheckCircle2, Loader2, ShieldCheck, Smartphone } from 'lucide-react';
import api from '@/services/api';
import { attachPaymongoQrPh } from '@/services/paymongoQrService';
import { formatPHP } from '@/lib/currency';

type Summary = { invoice: { invoice_number: string; issue_date: string; due_date: string; total: number; paid_amount: number; balance: number; status: string }; customer: { full_name: string; account_number_masked: string } };
type QrPayment = { checkout_id: string; payment_intent_id: string; client_key: string; public_key: string; base_url?: string; qr_image_url?: string | null; invoice_number: string; amount: number };

export default function InvoiceQuickPayPage(): React.JSX.Element {
  const { token = '' } = useParams();
  const location = useLocation();
  const base = `/customer-portal/pay/${encodeURIComponent(token)}`;
  const [summary, setSummary] = useState<Summary | null>(null);
  const [qr, setQr] = useState<QrPayment | null>(null);
  const [busy, setBusy] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const load = async () => {
    const response = await api.get(base);
    setSummary(response.data.data);
  };

  useEffect(() => {
    void (async () => {
      try {
        if (new URLSearchParams(location.search).get('payment') === 'success') {
          const result = await api.post(`${base}/gcash/reconcile`);
          setMessage(result.data.paid ? 'Payment confirmed and automatically recorded.' : 'PayMongo is still confirming the payment. Please refresh shortly.');
        }
        await load();
      } catch (requestError: any) {
        setError(requestError.response?.data?.message || 'This secure invoice payment link is unavailable.');
      }
    })();
  }, [token, location.search]);

  const gcash = async () => {
    setBusy('gcash'); setError('');
    try {
      const response = await api.post(`${base}/gcash`);
      window.location.assign(response.data.data.checkout_url);
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not open GCash checkout.'); setBusy(''); }
  };

  const qrPh = async () => {
    setBusy('qr'); setError('');
    try {
      let payment = (await api.post(`${base}/qr-ph`)).data.data as QrPayment;
      if (!payment.qr_image_url) {
        const attachment = await attachPaymongoQrPh({ publicKey: payment.public_key, baseUrl: payment.base_url, paymentIntentId: payment.payment_intent_id, clientKey: payment.client_key });
        payment = (await api.post(`${base}/qr-ph/${payment.checkout_id}/attach`, attachment)).data.data;
      }
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

  return <main className="grid min-h-screen place-items-center bg-slate-950 p-4 text-slate-900">
    <section className="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl sm:p-8">
      <div className="flex items-center gap-3"><span className="rounded-2xl bg-blue-600 p-3 text-white"><ShieldCheck /></span><div><p className="text-xs font-bold uppercase tracking-widest text-blue-600">SolarNet secure payment</p><h1 className="text-2xl font-bold">View and pay invoice</h1></div></div>
      {error && <p className="mt-5 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {message && <p className="mt-5 flex gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"><CheckCircle2 className="h-5 w-5 shrink-0" />{message}</p>}
      {!summary && !error && <div className="grid place-items-center py-16 text-blue-600"><Loader2 className="h-8 w-8 animate-spin" /></div>}
      {summary && <><div className="mt-6 rounded-2xl bg-slate-50 p-5"><p className="font-semibold">{summary.customer.full_name}</p><p className="text-xs text-slate-500">Account {summary.customer.account_number_masked} · {summary.invoice.invoice_number}</p><dl className="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt className="text-slate-500">Due date</dt><dd className="font-semibold">{summary.invoice.due_date}</dd></div><div><dt className="text-slate-500">Status</dt><dd className="font-semibold capitalize">{summary.invoice.status}</dd></div></dl><div className="mt-4 border-t pt-4"><p className="text-xs text-slate-500">Amount due</p><p className="text-3xl font-black text-blue-700">{formatPHP(summary.invoice.balance)}</p></div></div>
        {Number(summary.invoice.balance) > 0 ? <div className="mt-5 grid gap-3"><button onClick={() => void qrPh()} disabled={!!busy} className="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 font-bold text-white disabled:opacity-60"><Smartphone className="h-5 w-5" />{busy === 'qr' ? 'Preparing QR Ph…' : 'Scan QR Ph with GCash or bank'}</button><button onClick={() => void gcash()} disabled={!!busy} className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-bold text-white disabled:opacity-60">{busy === 'gcash' ? 'Opening secure checkout…' : 'Pay through GCash checkout'}</button></div> : <p className="mt-5 rounded-xl bg-emerald-50 p-4 text-center font-semibold text-emerald-800">This invoice is fully paid.</p>}
      </>}
      <p className="mt-5 text-center text-xs leading-5 text-slate-500">No portal login is required. This signed link opens only the invoice shown above. Payment is recorded only after PayMongo confirms it.</p>
    </section>
    {qr && <div className="fixed inset-0 grid place-items-center bg-slate-950/80 p-4"><section className="w-full max-w-sm rounded-2xl bg-white p-6 text-center"><h2 className="text-xl font-bold">Scan QR Ph</h2><p className="mt-1 text-sm text-slate-500">{qr.invoice_number} · {formatPHP(qr.amount)}</p><img src={qr.qr_image_url || ''} className="mx-auto my-5 h-64 w-64 rounded-xl border p-2" alt="PayMongo QR Ph" /><p className="text-sm font-semibold text-emerald-700">Waiting for payment confirmation…</p><button onClick={() => setQr(null)} className="mt-5 rounded-lg border px-4 py-2 text-sm font-semibold">Close</button></section></div>}
  </main>;
}
