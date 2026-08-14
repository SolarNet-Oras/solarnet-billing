import { useEffect, useState, type FormEvent } from 'react';
import { Banknote, CheckCircle2, Send, Smartphone, Landmark, X, WalletCards } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';

type Invoice = { id: string; invoice_number: string; due_date: string; balance: number; customer?: { account_number: string; full_name: string } };
type Remittance = { id: string; declared_amount: number; status: string; submitted_at: string; collector?: { name: string }; payments?: Array<{ payment_number: string; payment_method: string }> };
const peso = (value: number) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;

export default function RemittancesPage() {
  const { user } = useAuth();
  const isCollector = user?.role === 'collector' || user?.roles?.some((role) => typeof role === 'string' ? role === 'collector' : role.name === 'collector');
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [unremitted, setUnremitted] = useState(0);
  const [remittances, setRemittances] = useState<Remittance[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
  const [method, setMethod] = useState<'cash' | 'mobile_money' | 'bank_transfer'>('cash');
  const [amount, setAmount] = useState('');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');
  const [savingPayment, setSavingPayment] = useState(false);

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

  const openPayment = (invoice: Invoice) => { setSelectedInvoice(invoice); setAmount(String(invoice.balance)); setMethod('cash'); setReference(''); setNotes(''); };
  const collect = async (event: FormEvent) => {
    event.preventDefault();
    if (!selectedInvoice) return;
    const received = Number(amount);
    if (!Number.isFinite(received) || received <= 0 || received > selectedInvoice.balance) return window.alert(`Enter an amount from ₱0.01 to ${peso(selectedInvoice.balance)}.`);
    if (method !== 'cash' && !reference.trim()) return window.alert('Enter the GCash or bank reference number.');
    setSavingPayment(true);
    try {
      await api.post(`/collector/invoices/${selectedInvoice.id}/collect`, { amount: received, payment_method: method, reference: reference.trim() || undefined, notes: notes.trim() || undefined });
      setSelectedInvoice(null);
      window.alert('Payment recorded and added to your pending remittance.');
      await load();
    } catch (error: any) { window.alert(error.response?.data?.message || 'Could not record payment.'); }
    finally { setSavingPayment(false); }
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
    {isCollector ? <><section className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-primary/20 bg-primary/5 p-5"><div><p className="text-sm text-muted-foreground">Pending remittance</p><p className="text-3xl font-bold text-foreground">{peso(unremitted)}</p></div><button disabled={!unremitted} onClick={() => void submit()} className="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 font-semibold text-primary-foreground disabled:opacity-50"><Send className="h-4 w-4" /> Submit remittance</button></section><section className="overflow-x-auto rounded-2xl border border-border bg-card"><table className="w-full text-sm"><thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th className="px-4 py-3">Client</th><th className="px-4 py-3">Invoice</th><th className="px-4 py-3">Due</th><th className="px-4 py-3 text-right">Balance</th><th className="px-4 py-3 text-right">Receive</th></tr></thead><tbody className="divide-y divide-border">{invoices.map((invoice) => <tr key={invoice.id}><td className="px-4 py-3 font-medium">{invoice.customer?.full_name}<div className="text-xs font-normal text-muted-foreground">{invoice.customer?.account_number}</div></td><td className="px-4 py-3">{invoice.invoice_number}</td><td className="px-4 py-3">{invoice.due_date}</td><td className="px-4 py-3 text-right font-semibold">{peso(invoice.balance)}</td><td className="px-4 py-3 text-right"><button onClick={() => openPayment(invoice)} className="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:opacity-90">Receive payment</button></td></tr>)}{!loading && !invoices.length && <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">No due client accounts to collect.</td></tr>}</tbody></table></section></> : <section className="overflow-x-auto rounded-2xl border border-border bg-card"><table className="w-full text-sm"><thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th className="px-4 py-3">Collector</th><th className="px-4 py-3">Submitted</th><th className="px-4 py-3">Payments</th><th className="px-4 py-3 text-right">Declared</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Action</th></tr></thead><tbody className="divide-y divide-border">{remittances.map((item) => <tr key={item.id}><td className="px-4 py-3 font-medium">{item.collector?.name}</td><td className="px-4 py-3 text-xs">{new Date(item.submitted_at).toLocaleString()}</td><td className="px-4 py-3 text-xs">{item.payments?.map((payment) => `${payment.payment_number} (${payment.payment_method})`).join(', ')}</td><td className="px-4 py-3 text-right font-semibold">{peso(item.declared_amount)}</td><td className="px-4 py-3 capitalize">{item.status}</td><td className="px-4 py-3 text-right">{item.status === 'submitted' ? <button onClick={() => void receive(item)} className="inline-flex items-center gap-1 text-emerald-700 hover:underline"><CheckCircle2 className="h-4 w-4" /> Receive & verify</button> : '—'}</td></tr>)}{!loading && !remittances.length && <tr><td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">No remittances submitted.</td></tr>}</tbody></table></section>}
    {selectedInvoice && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/55 p-4" onClick={() => !savingPayment && setSelectedInvoice(null)}><form onSubmit={collect} onClick={(event) => event.stopPropagation()} className="w-full max-w-lg rounded-2xl border border-border bg-card shadow-2xl"><div className="flex items-start justify-between border-b border-border p-5"><div><h2 className="flex items-center gap-2 text-lg font-bold text-foreground"><WalletCards className="h-5 w-5 text-primary" /> Receive payment</h2><p className="mt-1 text-sm text-muted-foreground">{selectedInvoice.customer?.full_name} · {selectedInvoice.customer?.account_number}</p></div><button type="button" onClick={() => setSelectedInvoice(null)} className="rounded-lg p-2 hover:bg-muted" disabled={savingPayment}><X className="h-5 w-5" /></button></div><div className="space-y-5 p-5"><div className="rounded-xl bg-muted/50 p-4"><div className="flex justify-between text-sm text-muted-foreground"><span>{selectedInvoice.invoice_number}</span><span>Due {selectedInvoice.due_date}</span></div><p className="mt-1 text-2xl font-bold text-foreground">{peso(selectedInvoice.balance)}</p><p className="text-xs text-muted-foreground">Outstanding balance</p></div><div><label className="text-sm font-medium text-foreground">Amount received</label><div className="mt-1 flex items-center rounded-xl border border-input bg-background px-3"><span className="font-semibold text-muted-foreground">₱</span><input autoFocus type="number" min="0.01" max={selectedInvoice.balance} step="0.01" value={amount} onChange={(event) => setAmount(event.target.value)} className="w-full bg-transparent px-2 py-3 outline-none" required /></div><button type="button" onClick={() => setAmount(String(selectedInvoice.balance))} className="mt-1 text-xs text-primary hover:underline">Use full balance: {peso(selectedInvoice.balance)}</button></div><div><p className="text-sm font-medium text-foreground">How did the client pay?</p><div className="mt-2 grid grid-cols-3 gap-2"><button type="button" onClick={() => setMethod('cash')} className={`rounded-xl border p-3 text-sm font-semibold ${method === 'cash' ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted'}`}><Banknote className="mx-auto mb-1 h-5 w-5" />Cash</button><button type="button" onClick={() => setMethod('mobile_money')} className={`rounded-xl border p-3 text-sm font-semibold ${method === 'mobile_money' ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted'}`}><Smartphone className="mx-auto mb-1 h-5 w-5" />GCash</button><button type="button" onClick={() => setMethod('bank_transfer')} className={`rounded-xl border p-3 text-sm font-semibold ${method === 'bank_transfer' ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted'}`}><Landmark className="mx-auto mb-1 h-5 w-5" />Bank</button></div></div>{method !== 'cash' && <div><label className="text-sm font-medium text-foreground">{method === 'mobile_money' ? 'GCash reference number' : 'Bank transaction reference'}</label><input value={reference} onChange={(event) => setReference(event.target.value)} className="mt-1 w-full rounded-xl border border-input bg-background px-3 py-2.5 outline-none focus:ring-2 focus:ring-primary" placeholder="Enter reference number" required /></div>}<div><label className="text-sm font-medium text-foreground">Note <span className="text-muted-foreground">(optional)</span></label><textarea value={notes} onChange={(event) => setNotes(event.target.value)} className="mt-1 w-full rounded-xl border border-input bg-background px-3 py-2.5 outline-none focus:ring-2 focus:ring-primary" rows={2} placeholder="Optional collection note" /></div></div><div className="flex justify-end gap-3 border-t border-border p-5"><button type="button" onClick={() => setSelectedInvoice(null)} className="rounded-xl px-4 py-2.5 text-sm font-medium hover:bg-muted" disabled={savingPayment}>Cancel</button><button type="submit" disabled={savingPayment} className="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-60"><CheckCircle2 className="h-4 w-4" />{savingPayment ? 'Recording…' : 'Confirm payment'}</button></div></form></div>}
  </main></DashboardLayout>;
}
