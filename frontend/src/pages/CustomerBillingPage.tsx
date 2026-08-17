import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { ArrowLeft, CreditCard, FileText, ReceiptText, Smartphone } from 'lucide-react';
import customerPortalService from '@/services/customerPortalService';
import type { Invoice, Payment } from '@/types/api';
import { formatPHP } from '@/lib/currency';
import CustomerAppInstallCard from '@/components/customer/CustomerAppInstallCard';

type QrPayment = { checkout_id: string; payment_intent_id: string; client_key: string; public_key: string; base_url?: string; qr_image_url?: string | null; reference_number: string; invoice_number: string; amount: number; expires_at?: string | null };

export default function CustomerBillingPage(): React.JSX.Element {
  const navigate = useNavigate();
  const location = useLocation();
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [payingInvoiceId, setPayingInvoiceId] = useState<string | null>(null);
  const [qrPayment, setQrPayment] = useState<QrPayment | null>(null);

  useEffect(() => {
    const load = async (): Promise<void> => {
      try {
        const query = new URLSearchParams(location.search);
        const notificationId = query.get('notification');
        if (notificationId && /^[0-9a-f-]{36}$/i.test(notificationId)) {
          // Server-side ownership is checked before the audit log is updated.
          void customerPortalService.markPushNotificationClicked(notificationId).catch(() => undefined);
          query.delete('notification');
          const remaining = query.toString();
          window.history.replaceState({}, '', `/customer/billing${remaining ? `?${remaining}` : ''}`);
        }
        const returnedFromPayment = query.get('payment') === 'success';
        if (returnedFromPayment) {
          const result = await customerPortalService.reconcileLatestGcashCheckout();
          if (result.paid) setNotice('Your GCash payment was confirmed and has been applied to your account.');
          else if (result.found) setNotice('Your payment is still being confirmed by PayMongo. Refresh this page shortly.');
          window.history.replaceState({}, '', '/customer/billing');
        }
        const [invoiceResponse, paymentResponse] = await Promise.all([
          customerPortalService.getInvoices({ per_page: 100 }),
          customerPortalService.getPayments({ per_page: 100 }),
        ]);
        setInvoices(invoiceResponse.data ?? []);
        setPayments(paymentResponse.data ?? []);
      } catch (requestError: any) {
        setError(requestError.response?.data?.message || 'Could not load your billing history.');
        if (requestError.response?.status === 401) navigate('/customer/login?next=%2Fcustomer%2Fbilling', { replace: true });
      } finally {
        setLoading(false);
      }
    };
    void load();
  }, [location.search, navigate]);

  const payWithGcash = async (invoiceId: string): Promise<void> => {
    setError(''); setNotice(''); setPayingInvoiceId(invoiceId);
    try {
      const checkout = await customerPortalService.startGcashCheckout(invoiceId);
      // Keep the signed-in customer portal open. PayMongo owns the separate
      // checkout tab; cancelling it has no effect on the customer session.
      const paymentWindow = window.open(checkout.checkout_url, '_blank');
      if (!paymentWindow) {
        setError('Your browser blocked the secure GCash checkout window. Allow pop-ups for this site and try again.');
        setPayingInvoiceId(null);
        return;
      }
      paymentWindow.opener = null;
      const accessNotice = checkout.temporary_payment_access?.granted
        ? ' Payment access is enabled for up to 24 hours while you complete checkout.'
        : checkout.temporary_payment_access && !checkout.temporary_payment_access.success
          ? ' If checkout does not load, contact SolarNet because temporary payment access could not be enabled.'
          : '';
      setNotice(`GCash checkout opened for account ${checkout.account_number}. Your account stays signed in here. Your invoice is updated only after PayMongo confirms payment.` + accessNotice);
      setPayingInvoiceId(null);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not start GCash payment.');
      setPayingInvoiceId(null);
    }
  };

  const payWithQrPh = async (invoiceId: string): Promise<void> => {
    setError(''); setNotice(''); setPayingInvoiceId(invoiceId);
    try {
      const payment = await customerPortalService.startQrPhPayment(invoiceId);
      setQrPayment(payment);
      setNotice(`PayMongo QR Ph is ready for ${payment.invoice_number}. Scan it with GCash or a supported banking app. Your invoice changes only after PayMongo confirms payment.`);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || requestError.message || 'Could not start QR Ph payment.');
    } finally { setPayingInvoiceId(null); }
  };

  useEffect(() => {
    if (!qrPayment) return;
    const interval = window.setInterval(async () => {
      try {
        const result = await customerPortalService.reconcileQrPhPayment(qrPayment.checkout_id);
        if (result.paid) {
          setNotice('Your QR Ph payment was confirmed and applied to your account.');
          setQrPayment(null);
          const [invoiceResponse, paymentResponse] = await Promise.all([
            customerPortalService.getInvoices({ per_page: 100 }),
            customerPortalService.getPayments({ per_page: 100 }),
          ]);
          setInvoices(invoiceResponse.data ?? []);
          setPayments(paymentResponse.data ?? []);
        }
      } catch { /* webhook/reconciliation remains authoritative; keep waiting */ }
    }, 5000);
    return () => window.clearInterval(interval);
  }, [qrPayment]);

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="border-b bg-white"><div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4"><Link to="/customer/dashboard" className="inline-flex items-center gap-2 text-sm font-medium text-slate-700"><ArrowLeft className="h-4 w-4" /> Dashboard</Link><div className="font-semibold text-slate-900">SolarNet Billing History</div></div></header>
      <main className="mx-auto max-w-6xl space-y-8 px-4 py-8">
        <div><h1 className="text-3xl font-bold text-slate-900">Invoices & payments</h1><p className="mt-1 text-slate-600">Your account billing history and recorded payments.</p></div>
        {error && <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{error}</div>}
        {notice && <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">{notice}</div>}
        <CustomerAppInstallCard autoConnectGrantedPermission showInstall={false} />
        {loading ? <div className="py-16 text-center text-slate-500">Loading billing history…</div> : <>
          <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center gap-2 border-b px-5 py-4"><FileText className="h-5 w-5 text-blue-600" /><h2 className="font-semibold text-slate-900">Invoices</h2></div>
            <div className="overflow-x-auto"><table className="w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-5 py-3">Invoice</th><th className="px-5 py-3">Issued</th><th className="px-5 py-3">Due</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Total</th><th className="px-5 py-3 text-right">Balance</th><th className="px-5 py-3 text-right">Payment</th></tr></thead><tbody className="divide-y divide-slate-100">{invoices.map((invoice) => <tr key={invoice.id}><td className="px-5 py-4 font-medium text-slate-900">{invoice.invoice_number}</td><td className="px-5 py-4 text-slate-600">{invoice.issue_date}</td><td className="px-5 py-4 text-slate-600">{invoice.due_date}</td><td className="px-5 py-4"><Status status={invoice.status} /></td><td className="px-5 py-4 text-right">{formatPHP(invoice.total)}</td><td className="px-5 py-4 text-right font-semibold">{formatPHP(invoice.balance)}</td><td className="px-5 py-4 text-right">{Number(invoice.balance) > 0 && <div className="flex flex-col items-end gap-1"><button onClick={() => void payWithQrPh(invoice.id)} disabled={payingInvoiceId !== null} className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"><Smartphone className="h-3.5 w-3.5" /> {payingInvoiceId === invoice.id ? 'Preparing…' : 'Pay with QR Ph'}</button><button onClick={() => void payWithGcash(invoice.id)} disabled={payingInvoiceId !== null} className="text-xs font-medium text-blue-700 hover:underline disabled:opacity-50">Online GCash checkout</button></div>}</td></tr>)}{invoices.length === 0 && <tr><td colSpan={7} className="px-5 py-10 text-center text-slate-500">No invoices are available yet.</td></tr>}</tbody></table></div>
          </section>
          <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center gap-2 border-b px-5 py-4"><ReceiptText className="h-5 w-5 text-emerald-600" /><h2 className="font-semibold text-slate-900">Payment history</h2></div>
            <div className="overflow-x-auto"><table className="w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-5 py-3">Receipt</th><th className="px-5 py-3">Invoice</th><th className="px-5 py-3">Date</th><th className="px-5 py-3">Method</th><th className="px-5 py-3 text-right">Amount</th></tr></thead><tbody className="divide-y divide-slate-100">{payments.map((payment) => <tr key={payment.id}><td className="px-5 py-4 font-medium text-slate-900">{payment.payment_number}</td><td className="px-5 py-4 text-slate-600">{payment.invoice?.invoice_number ?? '—'}</td><td className="px-5 py-4 text-slate-600">{payment.payment_date}</td><td className="px-5 py-4 capitalize text-slate-600">{payment.payment_method.replace('_', ' ')}</td><td className="px-5 py-4 text-right font-semibold text-emerald-700">{formatPHP(payment.amount)}</td></tr>)}{payments.length === 0 && <tr><td colSpan={5} className="px-5 py-10 text-center text-slate-500">No payments have been recorded yet.</td></tr>}</tbody></table></div>
          </section>
          <div className="flex justify-end"><Link to="/customer/dashboard" className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white"><CreditCard className="h-4 w-4" /> Back to account</Link></div>
        </>}
        {qrPayment && <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"><section className="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-2xl"><h2 className="text-xl font-bold text-slate-900">Pay SolarNet with QR Ph</h2><p className="mt-1 text-sm text-slate-600">{qrPayment.invoice_number} · {formatPHP(qrPayment.amount)}</p><img src={qrPayment.qr_image_url || ''} alt="PayMongo QR Ph payment code" className="mx-auto my-5 h-64 w-64 rounded-xl border bg-white p-2" /><p className="text-sm font-medium text-emerald-700">Waiting for PayMongo confirmation…</p><p className="mt-2 text-xs text-slate-500">Scan with GCash or a supported bank/e-wallet app. This QR is single-use and expires automatically.</p><button onClick={() => setQrPayment(null)} className="mt-5 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Close</button></section></div>}
      </main>
    </div>
  );
}

function Status({ status }: { status: Invoice['status'] }): React.JSX.Element {
  const style = status === 'paid' ? 'bg-emerald-100 text-emerald-700' : status === 'overdue' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700';
  return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold capitalize ${style}`}>{status}</span>;
}
