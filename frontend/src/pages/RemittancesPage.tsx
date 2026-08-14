import { useEffect, useState } from 'react';
import { Banknote, CheckCircle2, Send, Smartphone, Landmark } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';

type Invoice = { id: string; invoice_number: string; due_date: string; balance: number; customer?: { account_number: string; full_name: string; contact_number?: string } };
type Remittance = { id: string; declared_amount: number; received_amount?: number; status: string; submitted_at: string; collector?: { name: string; email: string }; payments?: Array<{ payment_number: string; amount: number; payment_method: string }> };
const peso = (value: number) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;

export default function RemittancesPage() {
  const { user } = useAuth();
  const isCollector = user?.role === 'collector' || user?.roles?.some((role) => typeof role === 'string' ? role === 'collector' : role.name === 'collector');
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [unremitted, setUnremitted] = useState(0);
  const [remittances, setRemittances] = useState<Remittance[]>([]);
  const [loading, setLoading] = useState(true);

  const load = async () => {
    setLoading(true);
    try {
      if (isCollector) {
        const response = await api.get('/collector/dashboard');
        setInvoices(response.data?.invoices?.data || []);
        setUnremitted(Number(response.data?.unremitted_amount || 0));
      } else {
        const response = await api.get('/remittances');
        setRemittances(response.data?.data || []);
      }
    } finally { setLoading(false); }
  };
  useEffect(() => { void load(); }, [isCollector]);

  const collect = async (invoice: Invoice) => {
    const amount = window.prompt(`Amount received for ${invoice.customer?.full_name} (balance ${peso(invoice.balance)}):`, String(invoice.balance));
    if (!amount) return;
    const method = window.prompt('Payment method: cash, gcash, or bank', 'cash')?.toLowerCase();
    const paymentMethod = method === 'gcash' ? 'mobile_money' : method === 'bank' ? 'bank_transfer' : method === 'cash' ? 'cash' : null;
    if (!paymentMethod) return window.alert('Use cash, gcash, or bank.');
    const reference = paymentMethod === 'cash' ? '' : window.prompt('Enter the GCash or bank reference number:') || '';
    try {
      await api.post(`/collector/invoices/${invoice.id}/collect`, { amount, payment_method: paymentMethod, reference });
      window.alert('Payment received. It is now pending in your remittance.');
      await load();
    } catch (error: any) { window.alert(error.response?.data?.message || 'Could not record payment.'); }
  };
  const submit = async () => {
    if (!window.confirm(`Submit ${peso(unremitted)} for office verification?`)) return;
    try { await api.post('/collector/remittances', {}); window.alert('Remittance submitted.'); await load(); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not submit remittance.'); }
  };
  const receive = async (item: Remittance) => {
    const received = window.prompt(`Declared amount is ${peso(item.declared_amount)}. Enter actual amount received:`, String(item.declared_amount));
    if (received === null) return;
    const notes = Number(received) !== Number(item.declared_amount) ? window.prompt('Discrepancy note (required for an unmatched amount):') : '';
    if (Number(received) !== Number(item.declared_amount) && !notes) return window.alert('A discrepancy note is required.');
    try { await api.post(`/remittances/${item.id}/receive`, { received_amount: received, notes }); window.alert('Remittance verified.'); await load(); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not verify remittance.'); }
  };

  return <DashboardLayout><main className="space-y-6 p-4 md:p-6">
    <header><h1 className="flex items-center gap-2 text-2xl font-bold text-foreground"><Banknote className="h-6 w-6 text-primary" /> {isCollector ? 'Collection Desk' : 'Remittances'}</h1><p className="mt-1 text-sm text-muted-foreground">{isCollector ? 'Due accounts only. Record collections, then submit them for office verification.' : 'Verify collector remittances against the actual amount received.'}</p></header>
    {isCollector ? <>
      <section className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-primary/20 bg-primary/5 p-5"><div><p className="text-sm text-muted-foreground">Pending remittance</p><p className="text-3xl font-bold text-foreground">{peso(unremitted)}</p></div><button disabled={!unremitted} onClick={() => void submit()} className="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 font-semibold text-primary-foreground disabled:opacity-50"><Send className="h-4 w-4" /> Submit remittance</button></section>
      <section className="overflow-x-auto rounded-2xl border border-border bg-card"><table className="w-full text-sm"><thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th className="px-4 py-3">Client</th><th className="px-4 py-3">Invoice</th><th className="px-4 py-3">Due</th><th className="px-4 py-3 text-right">Balance</th><th className="px-4 py-3 text-right">Receive</th></tr></thead><tbody className="divide-y divide-border">{invoices.map(i => <tr key={i.id}><td className="px-4 py-3 font-medium">{i.customer?.full_name}<div className="text-xs font-normal text-muted-foreground">{i.customer?.account_number}</div></td><td className="px-4 py-3">{i.invoice_number}</td><td className="px-4 py-3">{i.due_date}</td><td className="px-4 py-3 text-right font-semibold">{peso(i.balance)}</td><td className="px-4 py-3 text-right"><button onClick={() => void collect(i)} className="text-primary hover:underline">Record payment</button></td></tr>)}{!loading && !invoices.length && <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">No due client accounts to collect.</td></tr>}</tbody></table></section>
    </> : <section className="overflow-x-auto rounded-2xl border border-border bg-card"><table className="w-full text-sm"><thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th className="px-4 py-3">Collector</th><th className="px-4 py-3">Submitted</th><th className="px-4 py-3">Payments</th><th className="px-4 py-3 text-right">Declared</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Action</th></tr></thead><tbody className="divide-y divide-border">{remittances.map(r => <tr key={r.id}><td className="px-4 py-3 font-medium">{r.collector?.name}</td><td className="px-4 py-3 text-xs">{new Date(r.submitted_at).toLocaleString()}</td><td className="px-4 py-3 text-xs">{r.payments?.map(p => `${p.payment_number} (${p.payment_method})`).join(', ')}</td><td className="px-4 py-3 text-right font-semibold">{peso(r.declared_amount)}</td><td className="px-4 py-3 capitalize">{r.status}</td><td className="px-4 py-3 text-right">{r.status === 'submitted' ? <button onClick={() => void receive(r)} className="inline-flex items-center gap-1 text-emerald-700 hover:underline"><CheckCircle2 className="h-4 w-4" /> Receive & verify</button> : '—'}</td></tr>)}{!loading && !remittances.length && <tr><td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">No remittances submitted.</td></tr>}</tbody></table></section>}
  </main></DashboardLayout>;
}
