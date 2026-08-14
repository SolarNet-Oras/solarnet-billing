import { useEffect, useState } from 'react';
import { Banknote, ExternalLink, Landmark, Send, Smartphone, X } from 'lucide-react';
import QRCode from 'qrcode';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';

type Invoice = { id: string; invoice_number: string; due_date: string; balance: number; previous_balance?: number; customer?: { account_number: string; full_name: string; address?: string } };
type Checkout = { checkout_url: string; reference_number: string; invoice_number: string; checkout_session_id?: string };
type Remittance = { id: string; declared_amount: number; status: string; submitted_at: string; collector?: { name: string } };
const peso = (value: number) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;

export default function RemittancesPage() {
  const { user } = useAuth();
  const collector = user?.role === 'collector' || user?.roles?.some((role) => typeof role === 'string' ? role === 'collector' : role.name === 'collector');
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [unremitted, setUnremitted] = useState(0);
  const [remittances, setRemittances] = useState<Remittance[]>([]);
  const [selected, setSelected] = useState<Invoice | null>(null);
  const [method, setMethod] = useState<'cash' | 'mobile_money' | 'bank_transfer'>('cash');
  const [checkout, setCheckout] = useState<Checkout | null>(null);
  const [qrCode, setQrCode] = useState('');
  const [amount, setAmount] = useState('');
  const [reference, setReference] = useState('');
  const [saving, setSaving] = useState(false);

  const load = async () => {
    if (collector) {
      const response = await api.get('/collector/dashboard');
      setInvoices(response.data?.invoices?.data || []);
      setUnremitted(Number(response.data?.unremitted_amount || 0));
    } else {
      const response = await api.get('/remittances');
      setRemittances(response.data?.data || []);
    }
  };

  useEffect(() => { void load(); }, [collector]);

  const open = (invoice: Invoice) => {
    setSelected(invoice); setMethod('cash'); setAmount(String(invoice.balance)); setReference(''); setCheckout(null); setQrCode('');
  };
  const close = () => { setSelected(null); setCheckout(null); setQrCode(''); };

  const startGcash = async () => {
    if (!selected) return;
    setSaving(true);
    try {
      const response = await api.post(`/collector/invoices/${selected.id}/gcash-checkout`);
      const created = response.data.checkout as Checkout;
      setCheckout(created);
      setQrCode(await QRCode.toDataURL(created.checkout_url, { width: 320, margin: 2, errorCorrectionLevel: 'M' }));
    } catch (error: any) {
      window.alert(error.response?.data?.message || 'Could not create a PayMongo GCash checkout.');
    } finally { setSaving(false); }
  };

  const verifyGcash = async () => {
    if (!selected || !checkout) return;
    setSaving(true);
    try {
      const response = await api.post(`/collector/invoices/${selected.id}/gcash-checkouts/${checkout.checkout_session_id}/reconcile`);
      if (response.data.paid) { window.alert('Payment confirmed directly by PayMongo.'); close(); await load(); }
      else window.alert('PayMongo has not confirmed this payment yet. Ask the client to complete checkout, then check again.');
    } catch (error: any) { window.alert(error.response?.data?.message || 'Could not check PayMongo payment status.'); }
    finally { setSaving(false); }
  };

  const recordManual = async () => {
    if (!selected) return;
    const received = Number(amount);
    if (!Number.isFinite(received) || received <= 0 || received > selected.balance) return window.alert(`Enter an amount from ₱0.01 to ${peso(selected.balance)}.`);
    if (method === 'bank_transfer' && !reference.trim()) return window.alert('Enter the bank transaction reference.');
    setSaving(true);
    try { await api.post(`/collector/invoices/${selected.id}/collect`, { amount: received, payment_method: method, reference: reference.trim() || undefined }); close(); await load(); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not record payment.'); }
    finally { setSaving(false); }
  };
  const submit = async () => { if (window.confirm(`Submit ${peso(unremitted)} for office verification?`)) { await api.post('/collector/remittances', {}); await load(); } };

  return <DashboardLayout><main className="space-y-6 p-4 md:p-6">
    <header><h1 className="flex items-center gap-2 text-2xl font-bold"><Banknote className="text-primary" />{collector ? 'Collection Desk' : 'Remittances'}</h1></header>
    {collector ? <>
      <section className="flex items-center justify-between rounded-2xl border bg-primary/5 p-5"><div><p className="text-sm text-muted-foreground">Pending remittance</p><p className="text-3xl font-bold">{peso(unremitted)}</p></div><button disabled={!unremitted} onClick={() => void submit()} className="rounded-xl bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"><Send className="mr-2 inline h-4 w-4" />Submit remittance</button></section>
      <section className="overflow-x-auto rounded-2xl border bg-card"><table className="w-full text-sm"><thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th className="p-4">Client</th><th className="p-4">Address</th><th className="p-4">Due Date</th><th className="p-4 text-right">Balance</th><th className="p-4">Collectibles</th><th className="p-4 text-right">Receive / Action</th></tr></thead><tbody>{invoices.map((invoice) => <tr className="border-t" key={invoice.id}><td className="p-4 font-medium">{invoice.customer?.full_name}<small className="block text-muted-foreground">{invoice.customer?.account_number}</small></td><td className="p-4">{invoice.customer?.address || 'To be updated'}</td><td className="p-4">{invoice.due_date.slice(0, 10)}</td><td className="p-4 text-right font-semibold">{peso(invoice.balance)}</td><td className="p-4">{invoice.invoice_number}{Number(invoice.previous_balance || 0) > 0 && <small className="block text-amber-700">Previous balance: {peso(Number(invoice.previous_balance))}</small>}</td><td className="p-4 text-right"><button onClick={() => open(invoice)} className="rounded-lg bg-primary px-3 py-1.5 text-primary-foreground">Receive payment</button></td></tr>)}{!invoices.length && <tr><td className="p-10 text-center text-muted-foreground" colSpan={6}>No due client accounts to collect.</td></tr>}</tbody></table></section>
    </> : <section className="rounded-2xl border bg-card p-5">{remittances.map((item) => <div className="border-b py-3" key={item.id}>{item.collector?.name} · {peso(item.declared_amount)} · {item.status}</div>)}{!remittances.length && 'No remittances submitted.'}</section>}
    {selected && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"><div className="w-full max-w-lg rounded-2xl bg-card shadow-2xl"><div className="flex justify-between border-b p-5"><div><h2 className="font-bold">Receive payment</h2><p className="text-sm text-muted-foreground">{selected.customer?.full_name} · {selected.invoice_number}</p></div><button onClick={close}><X /></button></div><div className="space-y-4 p-5"><div className="rounded-xl bg-muted p-4"><p className="text-xs text-muted-foreground">Outstanding balance</p><p className="text-2xl font-bold">{peso(selected.balance)}</p></div><div className="grid grid-cols-3 gap-2"><button onClick={() => setMethod('cash')} className={`rounded-xl border p-3 ${method === 'cash' ? 'border-primary bg-primary/10' : ''}`}><Banknote className="mx-auto" />Cash</button><button onClick={() => setMethod('mobile_money')} className={`rounded-xl border p-3 ${method === 'mobile_money' ? 'border-primary bg-primary/10' : ''}`}><Smartphone className="mx-auto" />GCash</button><button onClick={() => setMethod('bank_transfer')} className={`rounded-xl border p-3 ${method === 'bank_transfer' ? 'border-primary bg-primary/10' : ''}`}><Landmark className="mx-auto" />Bank</button></div>{method === 'mobile_money' ? <div className="rounded-xl border border-primary/20 bg-primary/5 p-4"><p className="font-semibold">PayMongo GCash checkout</p><p className="mt-1 text-sm text-muted-foreground">Generate a QR specific to this client and invoice. It appears here—no popup is needed.</p>{qrCode && <div className="mt-4 rounded-xl bg-white p-4 text-center"><img src={qrCode} alt={`GCash QR for ${selected.invoice_number}`} width="320" height="320" className="mx-auto max-w-full" /><p className="mt-2 text-xs text-slate-600">Scan with GCash to pay {peso(selected.balance)}.</p></div>}{checkout ? <><a href={checkout.checkout_url} target="_blank" rel="noreferrer" className="mt-3 inline-flex items-center gap-2 text-primary underline"><ExternalLink className="h-4 w-4" />Open direct payment link</a><button disabled={saving} onClick={() => void verifyGcash()} className="mt-3 block rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground">{saving ? 'Checking…' : 'Check PayMongo payment status'}</button></> : <button disabled={saving} onClick={() => void startGcash()} className="mt-3 rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground">{saving ? 'Generating QR…' : 'Generate GCash QR code'}</button>}</div> : <><label className="block text-sm">Amount received<input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} className="mt-1 w-full rounded-lg border p-2" /></label>{method === 'bank_transfer' && <label className="block text-sm">Bank reference<input value={reference} onChange={(e) => setReference(e.target.value)} className="mt-1 w-full rounded-lg border p-2" required /></label>}<button disabled={saving} onClick={() => void recordManual()} className="rounded-lg bg-primary px-4 py-2 text-primary-foreground">Confirm {method === 'cash' ? 'cash' : 'bank'} payment</button></>}</div></div></div>}
  </main></DashboardLayout>;
}
