import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, CreditCard, FileText, ReceiptText, Smartphone } from 'lucide-react';
import customerPortalService from '@/services/customerPortalService';
import type { Invoice, Payment } from '@/types/api';
import { formatPHP } from '@/lib/currency';

export default function CustomerBillingPage(): React.JSX.Element {
  const navigate = useNavigate();
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [payingInvoiceId, setPayingInvoiceId] = useState<string | null>(null);

  useEffect(() => {
    const load = async (): Promise<void> => {
      try {
        const [invoiceResponse, paymentResponse] = await Promise.all([
          customerPortalService.getInvoices({ per_page: 100 }),
          customerPortalService.getPayments({ per_page: 100 }),
        ]);
        setInvoices(invoiceResponse.data ?? []);
        setPayments(paymentResponse.data ?? []);
      } catch (requestError: any) {
        setError(requestError.response?.data?.message || 'Could not load your billing history.');
        if (requestError.response?.status === 401) navigate('/customer/login', { replace: true });
      } finally {
        setLoading(false);
      }
    };
    void load();
  }, [navigate]);

  const payWithGcash = async (invoiceId: string): Promise<void> => {
    setError(''); setPayingInvoiceId(invoiceId);
    try {
      const checkout = await customerPortalService.startGcashCheckout(invoiceId);
      window.location.assign(checkout.checkout_url);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not start GCash payment.');
      setPayingInvoiceId(null);
    }
  };

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="border-b bg-white"><div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4"><Link to="/customer/dashboard" className="inline-flex items-center gap-2 text-sm font-medium text-slate-700"><ArrowLeft className="h-4 w-4" /> Dashboard</Link><div className="font-semibold text-slate-900">SolarNet Billing History</div></div></header>
      <main className="mx-auto max-w-6xl space-y-8 px-4 py-8">
        <div><h1 className="text-3xl font-bold text-slate-900">Invoices & payments</h1><p className="mt-1 text-slate-600">Your account billing history and recorded payments.</p></div>
        {error && <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{error}</div>}
        {loading ? <div className="py-16 text-center text-slate-500">Loading billing history…</div> : <>
          <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center gap-2 border-b px-5 py-4"><FileText className="h-5 w-5 text-blue-600" /><h2 className="font-semibold text-slate-900">Invoices</h2></div>
            <div className="overflow-x-auto"><table className="w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-5 py-3">Invoice</th><th className="px-5 py-3">Issued</th><th className="px-5 py-3">Due</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Total</th><th className="px-5 py-3 text-right">Balance</th><th className="px-5 py-3 text-right">Payment</th></tr></thead><tbody className="divide-y divide-slate-100">{invoices.map((invoice) => <tr key={invoice.id}><td className="px-5 py-4 font-medium text-slate-900">{invoice.invoice_number}</td><td className="px-5 py-4 text-slate-600">{invoice.issue_date}</td><td className="px-5 py-4 text-slate-600">{invoice.due_date}</td><td className="px-5 py-4"><Status status={invoice.status} /></td><td className="px-5 py-4 text-right">{formatPHP(invoice.total)}</td><td className="px-5 py-4 text-right font-semibold">{formatPHP(invoice.balance)}</td><td className="px-5 py-4 text-right">{Number(invoice.balance) > 0 && <button onClick={() => void payWithGcash(invoice.id)} disabled={payingInvoiceId !== null} className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50"><Smartphone className="h-3.5 w-3.5" /> {payingInvoiceId === invoice.id ? 'Opening…' : 'Pay with GCash'}</button>}</td></tr>)}{invoices.length === 0 && <tr><td colSpan={7} className="px-5 py-10 text-center text-slate-500">No invoices are available yet.</td></tr>}</tbody></table></div>
          </section>
          <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center gap-2 border-b px-5 py-4"><ReceiptText className="h-5 w-5 text-emerald-600" /><h2 className="font-semibold text-slate-900">Payment history</h2></div>
            <div className="overflow-x-auto"><table className="w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-5 py-3">Receipt</th><th className="px-5 py-3">Invoice</th><th className="px-5 py-3">Date</th><th className="px-5 py-3">Method</th><th className="px-5 py-3 text-right">Amount</th></tr></thead><tbody className="divide-y divide-slate-100">{payments.map((payment) => <tr key={payment.id}><td className="px-5 py-4 font-medium text-slate-900">{payment.payment_number}</td><td className="px-5 py-4 text-slate-600">{payment.invoice?.invoice_number ?? '—'}</td><td className="px-5 py-4 text-slate-600">{payment.payment_date}</td><td className="px-5 py-4 capitalize text-slate-600">{payment.payment_method.replace('_', ' ')}</td><td className="px-5 py-4 text-right font-semibold text-emerald-700">{formatPHP(payment.amount)}</td></tr>)}{payments.length === 0 && <tr><td colSpan={5} className="px-5 py-10 text-center text-slate-500">No payments have been recorded yet.</td></tr>}</tbody></table></div>
          </section>
          <div className="flex justify-end"><Link to="/customer/dashboard" className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white"><CreditCard className="h-4 w-4" /> Back to account</Link></div>
        </>}
      </main>
    </div>
  );
}

function Status({ status }: { status: Invoice['status'] }): React.JSX.Element {
  const style = status === 'paid' ? 'bg-emerald-100 text-emerald-700' : status === 'overdue' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700';
  return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold capitalize ${style}`}>{status}</span>;
}
