import { useEffect, useMemo, useState } from 'react';
import { Banknote, Calculator, CheckCircle2, Landmark, Send, Smartphone, X } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';

type Invoice = { id: string; invoice_number: string; due_date: string; balance: number; previous_balance?: number; customer?: { account_number: string; full_name: string; address?: string } };
type CashLine = { denomination: number; count: number; amount: number };
type Remittance = { id: string; declared_amount: number; cash_counted_amount?: number | null; cash_breakdown?: CashLine[] | null; status: string; submitted_at: string; liquidated_at?: string | null; liquidated_by?: string | null; received_at?: string | null; collector?: { name: string }; liquidator?: { name: string }; receiver?: { name: string }; payments?: { amount: number; payment_method: 'cash' | 'mobile_money' | 'bank_transfer' }[] };

const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 1] as const;
const peso = (amount: number) => `₱${Number(amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;
const dateTime = (value?: string | null) => value ? new Date(value).toLocaleString('en-PH') : '—';

export default function RemittancesPage() {
  const { user } = useAuth();
  const collector = user?.role === 'collector' || user?.roles?.some((role) => typeof role === 'string' ? role === 'collector' : role.name === 'collector');
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [unremitted, setUnremitted] = useState(0);
  const [remittances, setRemittances] = useState<Remittance[]>([]);
  const [paymentInvoice, setPaymentInvoice] = useState<Invoice | null>(null);
  const [paymentMethod, setPaymentMethod] = useState<'cash' | 'mobile_money' | 'bank_transfer'>('cash');
  const [paymentAmount, setPaymentAmount] = useState('');
  const [reference, setReference] = useState('');
  const [liquidationTarget, setLiquidationTarget] = useState<Remittance | null>(null);
  const [verifyTarget, setVerifyTarget] = useState<Remittance | null>(null);
  const [receivedAmount, setReceivedAmount] = useState('');
  const [counts, setCounts] = useState<Record<number, number>>({});
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const response = collector ? await api.get('/collector/dashboard') : await api.get('/remittances');
    if (collector) { setInvoices(response.data?.invoices?.data || []); setUnremitted(Number(response.data?.unremitted_amount || 0)); }
    else setRemittances(response.data?.data || []);
  };
  useEffect(() => { void load(); }, [collector]);

  const paymentTotals = (item: Remittance) => (item.payments || []).reduce((all, payment) => ({ ...all, [payment.payment_method]: (all[payment.payment_method] || 0) + Number(payment.amount) }), {} as Record<string, number>);
  const cashExpected = Number((liquidationTarget?.payments || []).filter((payment) => payment.payment_method === 'cash').reduce((sum, payment) => sum + Number(payment.amount), 0));
  const breakdown = useMemo<CashLine[]>(() => DENOMINATIONS.map((denomination) => ({ denomination, count: Number(counts[denomination] || 0), amount: denomination * Number(counts[denomination] || 0) })), [counts]);
  const cashCounted = breakdown.reduce((total, line) => total + line.amount, 0);
  const cashMatches = Math.round(cashExpected * 100) === Math.round(cashCounted * 100);

  const submitRemittance = async () => {
    if (!window.confirm(`Submit ${peso(unremitted)} to the cashier for liquidation?`)) return;
    setBusy(true);
    try { const response = await api.post('/collector/remittances', {}); window.alert(response.data?.message || 'Remittance submitted.'); await load(); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not submit the remittance.'); }
    finally { setBusy(false); }
  };
  const openPayment = (invoice: Invoice) => { setPaymentInvoice(invoice); setPaymentAmount(String(invoice.balance)); setPaymentMethod('cash'); setReference(''); };
  const recordPayment = async () => {
    if (!paymentInvoice) return;
    const amount = Number(paymentAmount);
    if (!Number.isFinite(amount) || amount <= 0 || amount > paymentInvoice.balance) return window.alert(`Enter an amount up to ${peso(paymentInvoice.balance)}.`);
    if (paymentMethod === 'bank_transfer' && !reference.trim()) return window.alert('Enter the bank transaction reference.');
    setBusy(true);
    try { await api.post(`/collector/invoices/${paymentInvoice.id}/collect`, { amount, payment_method: paymentMethod, reference: reference.trim() || undefined }); setPaymentInvoice(null); await load(); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not record payment.'); }
    finally { setBusy(false); }
  };
  const liquidate = async () => {
    if (!liquidationTarget || !cashMatches) return;
    setBusy(true);
    try { const response = await api.post(`/remittances/${liquidationTarget.id}/liquidate`, { cash_breakdown: breakdown.map(({ denomination, count }) => ({ denomination, count })) }); window.alert(response.data?.message || 'Cash liquidated.'); setLiquidationTarget(null); setCounts({}); await load(); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not liquidate cash.'); }
    finally { setBusy(false); }
  };
  const validate = async () => {
    if (!verifyTarget) return;
    const received = Number(receivedAmount);
    if (!Number.isFinite(received) || received < 0) return window.alert('Enter the amount received.');
    setBusy(true);
    try { const response = await api.post(`/remittances/${verifyTarget.id}/receive`, { received_amount: received }); window.alert(response.data?.message || 'Remittance validated.'); setVerifyTarget(null); await load(); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not validate remittance.'); }
    finally { setBusy(false); }
  };

  return <DashboardLayout><main className="space-y-6 p-4 md:p-6">
    <header><h1 className="flex items-center gap-2 text-2xl font-bold"><Banknote className="text-primary" />{collector ? 'Collection Desk' : 'Remittances'}</h1><p className="mt-1 text-sm text-muted-foreground">{collector ? 'Submit collected payments to the cashier. Only office staff may count and liquidate cash.' : 'Count cash, ensure it matches the recorded cash collection, then validate receipt.'}</p></header>
    {collector ? <>
      <section className="flex flex-col gap-4 rounded-2xl border bg-primary/5 p-5 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-sm text-muted-foreground">Pending remittance</p><p className="text-3xl font-bold">{peso(unremitted)}</p><p className="mt-1 text-xs text-muted-foreground">Submit directly. The cashier will complete cash liquidation.</p></div><button disabled={!unremitted || busy} onClick={() => void submitRemittance()} className="rounded-xl bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"><Send className="mr-2 inline h-4 w-4" />Submit remittance</button></section>
      <section className="overflow-x-auto rounded-2xl border bg-card"><table className="w-full text-sm"><thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th className="p-4">Client</th><th className="p-4">Address</th><th className="p-4">Due date</th><th className="p-4 text-right">Balance</th><th className="p-4">Invoice</th><th className="p-4 text-right">Action</th></tr></thead><tbody>{invoices.map((invoice) => <tr className="border-t" key={invoice.id}><td className="p-4 font-medium">{invoice.customer?.full_name}<small className="block text-muted-foreground">{invoice.customer?.account_number}</small></td><td className="p-4">{invoice.customer?.address || 'To be updated'}</td><td className="p-4">{invoice.due_date.slice(0, 10)}</td><td className="p-4 text-right font-semibold">{peso(invoice.balance)}</td><td className="p-4">{invoice.invoice_number}{Number(invoice.previous_balance || 0) > 0 && <small className="block text-amber-700">Previous balance: {peso(Number(invoice.previous_balance))}</small>}</td><td className="p-4 text-right"><button onClick={() => openPayment(invoice)} className="rounded-lg bg-primary px-3 py-1.5 text-primary-foreground">Receive payment</button></td></tr>)}{!invoices.length && <tr><td className="p-10 text-center text-muted-foreground" colSpan={6}>No due client accounts to collect.</td></tr>}</tbody></table></section>
    </> : <section className="space-y-3">{remittances.map((item) => { const totals = paymentTotals(item); const liquidated = Boolean(item.liquidated_at && item.liquidated_by); const matches = liquidated && Math.round(Number(item.cash_counted_amount || 0) * 100) === Math.round(Number(totals.cash || 0) * 100); return <article key={item.id} className="rounded-2xl border bg-card p-5"><div className="flex flex-col gap-4 md:flex-row md:justify-between"><div><h2 className="font-semibold">{item.collector?.name || 'Collector'} · {peso(item.declared_amount)}</h2><p className="mt-1 text-sm capitalize text-muted-foreground">{item.status} · submitted {dateTime(item.submitted_at)}</p><div className="mt-3 flex flex-wrap gap-3 text-xs text-muted-foreground"><span>Cash: {peso(totals.cash || 0)}</span><span>GCash: {peso(totals.mobile_money || 0)}</span><span>Bank: {peso(totals.bank_transfer || 0)}</span></div></div><div className="text-sm md:text-right"><p>Liquidated by <strong>{item.liquidator?.name || 'Awaiting cashier'}</strong></p><p className="text-xs text-muted-foreground">{dateTime(item.liquidated_at)}</p>{item.receiver && <><p className="mt-2">Received by <strong>{item.receiver.name}</strong></p><p className="text-xs text-muted-foreground">{dateTime(item.received_at)}</p></>}</div></div><div className={`mt-4 rounded-xl p-3 text-sm ${matches ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'}`}><strong>Cash liquidation:</strong> {liquidated ? `${peso(Number(item.cash_counted_amount))} counted ${matches ? 'matches recorded cash.' : `does not match ${peso(totals.cash || 0)}.`}` : 'Awaiting administrator/cashier cash count.'}</div>{item.cash_breakdown && <div className="mt-3 flex flex-wrap gap-2 text-xs text-muted-foreground">{item.cash_breakdown.filter((line) => line.count > 0).map((line) => <span key={line.denomination} className="rounded bg-muted px-2 py-1">{line.count} × ₱{line.denomination} = {peso(line.amount)}</span>)}</div>}{item.status === 'submitted' && !liquidated && <button onClick={() => { setLiquidationTarget(item); setCounts({}); }} className="mt-4 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"><Calculator className="mr-2 inline h-4 w-4" />Liquidate cash</button>}{item.status === 'submitted' && liquidated && <button disabled={!matches} onClick={() => { setVerifyTarget(item); setReceivedAmount(String(item.declared_amount)); }} className="mt-4 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50"><CheckCircle2 className="mr-2 inline h-4 w-4" />Validate received remittance</button>}</article>; })}{!remittances.length && <section className="rounded-2xl border bg-card p-8 text-center text-muted-foreground">No remittances submitted.</section>}</section>}
    {paymentInvoice && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"><div className="w-full max-w-md rounded-2xl bg-card shadow-2xl"><div className="flex justify-between border-b p-5"><div><h2 className="font-bold">Receive payment</h2><p className="text-sm text-muted-foreground">{paymentInvoice.customer?.full_name} · {paymentInvoice.invoice_number}</p></div><button onClick={() => setPaymentInvoice(null)}><X /></button></div><div className="space-y-4 p-5"><p className="rounded-xl bg-muted p-3 text-lg font-bold">Balance: {peso(paymentInvoice.balance)}</p><div className="grid grid-cols-3 gap-2"><button onClick={() => setPaymentMethod('cash')} className={`rounded-xl border p-3 ${paymentMethod === 'cash' ? 'border-primary bg-primary/10' : ''}`}><Banknote className="mx-auto" />Cash</button><button onClick={() => setPaymentMethod('mobile_money')} className={`rounded-xl border p-3 ${paymentMethod === 'mobile_money' ? 'border-primary bg-primary/10' : ''}`}><Smartphone className="mx-auto" />GCash</button><button onClick={() => setPaymentMethod('bank_transfer')} className={`rounded-xl border p-3 ${paymentMethod === 'bank_transfer' ? 'border-primary bg-primary/10' : ''}`}><Landmark className="mx-auto" />Bank</button></div><label className="block text-sm">Amount received<input type="number" min="0.01" max={paymentInvoice.balance} value={paymentAmount} onChange={(event) => setPaymentAmount(event.target.value)} className="mt-1 w-full rounded-lg border p-2" /></label>{paymentMethod === 'bank_transfer' && <label className="block text-sm">Bank reference<input value={reference} onChange={(event) => setReference(event.target.value)} className="mt-1 w-full rounded-lg border p-2" /></label>}<button disabled={busy} onClick={() => void recordPayment()} className="w-full rounded-xl bg-primary px-4 py-3 font-semibold text-primary-foreground">Confirm payment</button></div></div></div>}
    {liquidationTarget && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"><div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-card shadow-2xl"><div className="flex justify-between border-b p-5"><div><h2 className="flex items-center gap-2 text-lg font-bold"><Calculator className="text-primary" />Cash liquidation</h2><p className="mt-1 text-sm text-muted-foreground">Count the physical cash received from {liquidationTarget.collector?.name || 'the collector'}.</p></div><button onClick={() => setLiquidationTarget(null)}><X /></button></div><div className="space-y-4 p-5"><div className="grid grid-cols-2 gap-3"><div className="rounded-xl bg-muted p-3"><p className="text-xs text-muted-foreground">Recorded cash</p><p className="text-xl font-bold">{peso(cashExpected)}</p></div><div className={`rounded-xl p-3 ${cashMatches ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'}`}><p className="text-xs">Cash counted</p><p className="text-xl font-bold">{peso(cashCounted)}</p><p className="text-xs">{cashMatches ? 'Amounts match' : `Difference: ${peso(cashCounted - cashExpected)}`}</p></div></div><table className="w-full text-sm"><thead className="text-left text-xs uppercase text-muted-foreground"><tr><th className="pb-2">No. of pcs</th><th className="pb-2">Denomination</th><th className="pb-2 text-right">Amount</th></tr></thead><tbody>{DENOMINATIONS.map((denomination) => <tr className="border-t" key={denomination}><td className="py-2"><input min="0" type="number" value={counts[denomination] || ''} onChange={(event) => setCounts((current) => ({ ...current, [denomination]: Math.max(0, Number(event.target.value) || 0) }))} className="w-28 rounded-lg border bg-background px-3 py-2" /></td><td className="py-2 font-medium">₱{denomination.toLocaleString('en-PH')}</td><td className="py-2 text-right font-semibold">{peso(denomination * Number(counts[denomination] || 0))}</td></tr>)}</tbody></table><button disabled={busy || !cashMatches} onClick={() => void liquidate()} className="w-full rounded-xl bg-primary px-4 py-3 font-semibold text-primary-foreground disabled:opacity-50"><Calculator className="mr-2 inline h-4 w-4" />{busy ? 'Saving…' : 'Confirm cash liquidation'}</button></div></div></div>}
    {verifyTarget && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"><div className="w-full max-w-md rounded-2xl bg-card shadow-2xl"><div className="flex justify-between border-b p-5"><div><h2 className="font-bold">Validate remittance</h2><p className="text-sm text-muted-foreground">Cash has been liquidated. Record the total remittance received.</p></div><button onClick={() => setVerifyTarget(null)}><X /></button></div><div className="space-y-4 p-5"><p className="rounded-xl bg-emerald-50 p-3 text-lg font-bold text-emerald-900">Declared total: {peso(verifyTarget.declared_amount)}</p><label className="block text-sm">Amount received<input type="number" min="0" step="0.01" value={receivedAmount} onChange={(event) => setReceivedAmount(event.target.value)} className="mt-1 w-full rounded-lg border p-2" /></label><button disabled={busy} onClick={() => void validate()} className="w-full rounded-xl bg-primary px-4 py-3 font-semibold text-primary-foreground"><CheckCircle2 className="mr-2 inline h-4 w-4" />Validate received remittance</button></div></div></div>}
  </main></DashboardLayout>;
}
