import { useEffect, useMemo, useRef, useState } from 'react';
import { Banknote, Calculator, CheckCircle2, Eye, ExternalLink, Landmark, Send, Smartphone, X } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';
import { attachPaymongoQrPh } from '@/services/paymongoQrService';

type Invoice = { id: string; invoice_number: string; due_date: string; due_date_local?: string; balance: number; previous_balance?: number; customer_outstanding?: number; customer?: { account_number: string; full_name: string; address?: string } };
type CashLine = { denomination: number; kind: 'bill' | 'coin'; count: number; amount: number };
type Checkout = { checkout_url: string; reference_number: string; invoice_number: string; checkout_session_id?: string };
type QrPayment = { checkout_id: string; payment_intent_id: string; client_key: string; public_key: string; base_url?: string; qr_image_url?: string | null; reference_number: string; invoice_number: string; amount: number; status: string; expires_at?: string | null };
type RemittancePayment = { amount: number; payment_method: 'cash' | 'mobile_money' | 'bank_transfer'; payment_number: string; payment_date?: string | null; reference?: string | null; customer?: { account_number: string; full_name: string; address?: string | null } | null; invoice?: { invoice_number: string } | null; allocations?: { amount: number; invoice?: { invoice_number: string } | null }[] };
type Remittance = { id: string; declared_amount: number; cash_counted_amount?: number | null; cash_breakdown?: CashLine[] | null; liquidation_variance?: number; status: string; submitted_at: string; liquidated_at?: string | null; liquidated_by?: string | null; received_at?: string | null; collector?: { name: string }; liquidator?: { name: string }; receiver?: { name: string }; payments?: RemittancePayment[] };

const DENOMINATIONS = [{ denomination: 1000, kind: 'bill' }, { denomination: 500, kind: 'bill' }, { denomination: 200, kind: 'bill' }, { denomination: 100, kind: 'bill' }, { denomination: 50, kind: 'bill' }, { denomination: 20, kind: 'bill' }, { denomination: 20, kind: 'coin' }, { denomination: 10, kind: 'coin' }, { denomination: 5, kind: 'coin' }, { denomination: 1, kind: 'coin' }] as const;
const denominationKey = (denomination: number, kind: string) => `${denomination}_${kind}`;
const peso = (amount: number) => `₱${Number(amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;
const dateTime = (value?: string | null) => value ? new Date(value).toLocaleString('en-PH') : '—';

export default function RemittancesPage() {
  const { user } = useAuth();
  const collector = user?.role === 'collector' || user?.roles?.some((role) => typeof role === 'string' ? role === 'collector' : role.name === 'collector');
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [collectionSearch, setCollectionSearch] = useState('');
  const [collectionSort, setCollectionSort] = useState<'due_date' | 'address'>('due_date');
  const [unremitted, setUnremitted] = useState(0);
  const [remittances, setRemittances] = useState<Remittance[]>([]);
  const [remittanceMonth, setRemittanceMonth] = useState('');
  const [remittanceDateOrder, setRemittanceDateOrder] = useState<'newest' | 'oldest'>('newest');
  const [paymentInvoice, setPaymentInvoice] = useState<Invoice | null>(null);
  const [paymentMethod, setPaymentMethod] = useState<'cash' | 'mobile_money' | 'bank_transfer'>('cash');
  const [paymentAmount, setPaymentAmount] = useState('');
  const [reference, setReference] = useState('');
  const [checkout, setCheckout] = useState<Checkout | null>(null);
  const [qrPayment, setQrPayment] = useState<QrPayment | null>(null);
  const [qrCode, setQrCode] = useState('');
  const [liquidationTarget, setLiquidationTarget] = useState<Remittance | null>(null);
  const [verifyTarget, setVerifyTarget] = useState<Remittance | null>(null);
  const [detailsTarget, setDetailsTarget] = useState<Remittance | null>(null);
  const [receivedAmount, setReceivedAmount] = useState('');
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [shortageReason, setShortageReason] = useState('');
  const [expenseReceiptReference, setExpenseReceiptReference] = useState('');
  const [expenseReceipt, setExpenseReceipt] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const signatureCanvas = useRef<HTMLCanvasElement | null>(null);
  const remittanceSubmitInFlight = useRef(false);
  const liquidationInFlight = useRef(false);
  const validationInFlight = useRef(false);
  const [drawingSignature, setDrawingSignature] = useState(false);
  const [signaturePresent, setSignaturePresent] = useState(false);
  const [familySigner, setFamilySigner] = useState(false);
  const [familySignerName, setFamilySignerName] = useState('');
  const [cashStep, setCashStep] = useState<'details' | 'signature'>('details');

  const load = async (search = collectionSearch, sort = collectionSort, month = remittanceMonth, dateOrder = remittanceDateOrder) => {
    const response = collector
      ? await api.get('/collector/dashboard', { params: { per_page: 200, q: search.trim() || undefined, sort } })
      : await api.get('/remittances', { params: { month: month || undefined, date_order: dateOrder } });
    if (collector) {
      setInvoices((response.data?.invoices?.data || []).map((invoice: Invoice) => ({
        ...invoice,
        due_date: invoice.due_date_local || invoice.due_date,
      })));
      setUnremitted(Number(response.data?.unremitted_amount || 0));
    } else setRemittances(response.data?.data || []);
  };
  useEffect(() => { void load(collectionSearch, collectionSort, remittanceMonth, remittanceDateOrder); }, [collector, collectionSort, remittanceMonth, remittanceDateOrder]);
  useEffect(() => {
    if (!collector) return;
    const refreshDueClients = () => void load(collectionSearch, collectionSort);
    const timer = window.setInterval(refreshDueClients, 60_000);
    const refreshWhenVisible = () => { if (document.visibilityState === 'visible') refreshDueClients(); };
    window.addEventListener('focus', refreshDueClients);
    window.addEventListener('online', refreshDueClients);
    document.addEventListener('visibilitychange', refreshWhenVisible);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', refreshDueClients);
      window.removeEventListener('online', refreshDueClients);
      document.removeEventListener('visibilitychange', refreshWhenVisible);
    };
  }, [collector, collectionSearch, collectionSort]);

  const paymentTotals = (item: Remittance) => {
    const totals = (item.payments || []).reduce((all, payment) => ({ ...all, [payment.payment_method]: (all[payment.payment_method] || 0) + Number(payment.amount) }), {} as Record<string, number>);
    totals.cash = Number(totals.cash || 0) + Number(item.liquidation_variance || 0);
    return totals;
  };
  const cashExpected = Number((liquidationTarget?.payments || []).filter((payment) => payment.payment_method === 'cash').reduce((sum, payment) => sum + Number(payment.amount), 0));
  const breakdown = useMemo<CashLine[]>(() => DENOMINATIONS.map(({ denomination, kind }) => ({ denomination, kind, count: Number(counts[denominationKey(denomination, kind)] || 0), amount: denomination * Number(counts[denominationKey(denomination, kind)] || 0) })), [counts]);
  const cashCounted = breakdown.reduce((total, line) => total + line.amount, 0);
  const cashMatches = Math.round(cashExpected * 100) === Math.round(cashCounted * 100);
  const cashShortage = cashCounted < cashExpected;
  const liquidationReady = cashCounted >= cashExpected || (shortageReason === 'travel_expense_gas' && Boolean(expenseReceiptReference.trim()));

  const closePayment = () => { setPaymentInvoice(null); setCheckout(null); setQrPayment(null); setQrCode(''); setSignaturePresent(false); setFamilySigner(false); setFamilySignerName(''); setCashStep('details'); };
  const openPayment = (invoice: Invoice) => { setPaymentInvoice(invoice); setPaymentAmount(String(invoice.customer_outstanding || invoice.balance)); setPaymentMethod('cash'); setReference(''); setCheckout(null); setQrPayment(null); setQrCode(''); setSignaturePresent(false); setFamilySigner(false); setFamilySignerName(''); setCashStep('details'); };
  const signaturePoint = (event: React.PointerEvent<HTMLCanvasElement>) => {
    const canvas = signatureCanvas.current;
    if (!canvas) return null;
    const bounds = canvas.getBoundingClientRect();
    return { x: (event.clientX - bounds.left) * (canvas.width / bounds.width), y: (event.clientY - bounds.top) * (canvas.height / bounds.height) };
  };
  const startSignature = (event: React.PointerEvent<HTMLCanvasElement>) => {
    const canvas = signatureCanvas.current;
    const point = signaturePoint(event);
    if (!canvas || !point) return;
    canvas.setPointerCapture(event.pointerId);
    const context = canvas.getContext('2d');
    context?.beginPath();
    context?.moveTo(point.x, point.y);
    setDrawingSignature(true);
  };
  const drawSignature = (event: React.PointerEvent<HTMLCanvasElement>) => {
    if (!drawingSignature) return;
    const point = signaturePoint(event);
    const context = signatureCanvas.current?.getContext('2d');
    if (!point || !context) return;
    context.lineWidth = 5;
    context.lineCap = 'round';
    context.strokeStyle = '#0f172a';
    context.lineTo(point.x, point.y);
    context.stroke();
    setSignaturePresent(true);
  };
  const endSignature = () => setDrawingSignature(false);
  const clearSignature = () => {
    const canvas = signatureCanvas.current;
    canvas?.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
    setSignaturePresent(false);
  };
  const signatureFingerprint = () => {
    const canvas = signatureCanvas.current;
    const context = canvas?.getContext('2d');
    if (!canvas || !context) return '';
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
    return Array.from({ length: 32 }, (_, cell) => {
      const column = cell % 8;
      const row = Math.floor(cell / 8);
      let ink = 0;
      for (let y = Math.floor(row * canvas.height / 4); y < Math.floor((row + 1) * canvas.height / 4); y += 4) for (let x = Math.floor(column * canvas.width / 8); x < Math.floor((column + 1) * canvas.width / 8); x += 4) {
        const offset = (y * canvas.width + x) * 4;
        if (pixels[offset + 3] > 0 && pixels[offset] < 180) ink++;
      }
      return ink >= 3 ? '1' : '0';
    }).join('');
  };

  const submitRemittance = async () => {
    if (remittanceSubmitInFlight.current) return;
    if (!window.confirm(`Submit ${peso(unremitted)} to the cashier for validation? Only physical cash requires a bill count.`)) return;
    remittanceSubmitInFlight.current = true;
    setBusy(true);
    try { const response = await api.post('/collector/remittances', {}); window.alert(response.data?.message || 'Remittance submitted.'); await load(); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not submit the remittance.'); }
    finally { remittanceSubmitInFlight.current = false; setBusy(false); }
  };

  const recordPayment = async () => {
    if (!paymentInvoice || paymentMethod === 'mobile_money') return;
    const amount = Number(paymentAmount);
    const collectibleBalance = Number(paymentInvoice.customer_outstanding || paymentInvoice.balance);
    if (!Number.isFinite(amount) || amount <= 0 || amount > collectibleBalance) return window.alert(`Enter an amount up to ${peso(collectibleBalance)}.`);
    if (paymentMethod === 'bank_transfer' && !reference.trim()) return window.alert('Enter the bank transaction reference.');
    if (paymentMethod === 'cash' && cashStep === 'details') { setCashStep('signature'); return; }
    if (paymentMethod === 'cash' && !signaturePresent) return window.alert('The client or an authorized family member must sign before confirming cash payment.');
    setBusy(true);
    try {
      await api.post(`/collector/invoices/${paymentInvoice.id}/collect`, {
        amount,
        payment_method: paymentMethod,
        reference: reference.trim() || undefined,
        payer_signature: paymentMethod === 'cash' ? signatureCanvas.current?.toDataURL('image/png') : undefined,
        payer_signature_fingerprint: paymentMethod === 'cash' ? signatureFingerprint() : undefined,
        signature_signer_type: paymentMethod === 'cash' ? (familySigner ? 'family' : 'client') : undefined,
        signature_signer_name: paymentMethod === 'cash' && familySigner ? familySignerName.trim() : undefined,
      });
      closePayment();
      await load();
    } catch (error: any) { window.alert(error.response?.data?.message || 'Could not record payment.'); }
    finally { setBusy(false); }
  };

  const startGcashCheckout = async () => {
    if (!paymentInvoice) return;
    setBusy(true);
    try {
      const response = await api.post(`/collector/invoices/${paymentInvoice.id}/gcash-checkout`);
      const created = response.data?.checkout as Checkout;
      if (!created?.checkout_url) throw new Error('PayMongo did not return a checkout link.');
      setCheckout(created);
    } catch (error: any) { window.alert(error.response?.data?.message || error.message || 'Could not start GCash checkout.'); }
    finally { setBusy(false); }
  };

  const startQrPhPayment = async () => {
    if (!paymentInvoice) return;
    setBusy(true);
    try {
      const response = await api.post(`/collector/invoices/${paymentInvoice.id}/qr-ph`);
      let payment = response.data?.payment as QrPayment;
      if (!payment?.qr_image_url) {
        const qr = await attachPaymongoQrPh({ publicKey: payment.public_key, baseUrl: payment.base_url, paymentIntentId: payment.payment_intent_id, clientKey: payment.client_key });
        const attached = await api.post(`/collector/invoices/${paymentInvoice.id}/qr-ph/${payment.checkout_id}/attach`, qr);
        payment = attached.data?.payment as QrPayment;
      }
      if (!payment?.qr_image_url) throw new Error('PayMongo did not return the dynamic QR Ph image.');
      setQrPayment(payment);
      setQrCode(payment.qr_image_url);
    } catch (error: any) { window.alert(error.response?.data?.message || error.message || 'Could not start QR Ph payment.'); }
    finally { setBusy(false); }
  };

  const reconcileGcashCheckout = async (quiet = false) => {
    if (!paymentInvoice || (!checkout?.checkout_session_id && !qrPayment?.checkout_id)) return;
    try {
      const response = qrPayment
        ? await api.post(`/collector/invoices/${paymentInvoice.id}/qr-ph/${qrPayment.checkout_id}/reconcile`)
        : await api.post(`/collector/invoices/${paymentInvoice.id}/gcash-checkouts/${checkout?.checkout_session_id}/reconcile`);
      if (response.data?.paid) {
        if (!quiet) window.alert('GCash payment confirmed. The invoice has been updated.');
        closePayment();
        await load();
      } else if (!quiet) window.alert('Payment is still awaiting PayMongo confirmation. Please complete GCash, then check again.');
    } catch (error: any) { if (!quiet) window.alert(error.response?.data?.message || 'Could not check the GCash payment.'); }
  };

  useEffect(() => {
    if (!paymentInvoice || (!checkout?.checkout_session_id && !qrPayment?.checkout_id)) return;
    const interval = window.setInterval(() => { void reconcileGcashCheckout(true); }, 5000);
    return () => window.clearInterval(interval);
  }, [paymentInvoice, checkout, qrPayment]);

  const liquidate = async () => {
    if (!liquidationTarget || !liquidationReady || liquidationInFlight.current) return;
    liquidationInFlight.current = true;
    setBusy(true);
    try { const form = new FormData(); breakdown.forEach((row, index) => { form.append(`cash_breakdown[${index}][denomination]`, String(row.denomination)); form.append(`cash_breakdown[${index}][kind]`, row.kind); form.append(`cash_breakdown[${index}][count]`, String(row.count)); }); if (cashShortage) { form.append('shortage_reason', shortageReason); form.append('expense_receipt_reference', expenseReceiptReference.trim()); if (expenseReceipt) form.append('expense_receipt', expenseReceipt); } const response = await api.post(`/remittances/${liquidationTarget.id}/liquidate`, form); window.alert(response.data?.message || 'Cash liquidated.'); setLiquidationTarget(null); setCounts({}); setShortageReason(''); setExpenseReceiptReference(''); setExpenseReceipt(null); await load(); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not liquidate cash.'); }
    finally { liquidationInFlight.current = false; setBusy(false); }
  };
  const validate = async () => {
    if (!verifyTarget || validationInFlight.current) return;
    const received = Number(receivedAmount);
    if (!Number.isFinite(received) || received < 0) return window.alert('Enter the amount received.');
    validationInFlight.current = true;
    setBusy(true);
    try { const response = await api.post(`/remittances/${verifyTarget.id}/receive`, { received_amount: received }); window.alert(response.data?.message || 'Remittance validated.'); setVerifyTarget(null); await load(); }
    catch (error: any) { window.alert(error.response?.data?.message || 'Could not validate remittance.'); }
    finally { validationInFlight.current = false; setBusy(false); }
  };

  return <DashboardLayout><main className="space-y-6 p-4 md:p-6">
    {detailsTarget && <RemittanceDetailsModal remittance={detailsTarget} onClose={() => setDetailsTarget(null)} />}
    <header><h1 className="flex items-center gap-2 text-2xl font-bold"><Banknote className="text-primary" />{collector ? 'Collection Desk' : 'Remittances'}</h1><p className="mt-1 text-sm text-muted-foreground">{collector ? 'Cash collections are liquidated by the cashier. GCash is confirmed directly by PayMongo.' : 'Count physical cash, then validate the submitted remittance.'}</p></header>
    {collector ? <>
      <form onSubmit={(event) => { event.preventDefault(); void load(collectionSearch, collectionSort); }} className="grid gap-3 rounded-2xl border bg-card p-4 sm:grid-cols-[minmax(0,1fr)_220px_auto] sm:items-end">
        <label className="text-sm font-medium">Search collections<input value={collectionSearch} onChange={(event) => setCollectionSearch(event.target.value)} placeholder="Client, account, address, or invoice" className="mt-1 w-full rounded-lg border bg-background px-3 py-2" /></label>
        <label className="text-sm font-medium">Sort by<select value={collectionSort} onChange={(event) => setCollectionSort(event.target.value as 'due_date' | 'address')} className="mt-1 w-full rounded-lg border bg-background px-3 py-2"><option value="due_date">Due date</option><option value="address">Address A to Z</option></select></label>
        <button type="submit" className="rounded-lg bg-primary px-4 py-2 font-semibold text-primary-foreground">Search</button>
      </form>
      <section className="flex flex-col gap-4 rounded-2xl border bg-primary/5 p-5 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-sm text-muted-foreground">Pending remittance</p><p className="text-3xl font-bold">{peso(unremitted)}</p><p className="mt-1 text-xs text-muted-foreground">Only recorded cash requires cashier liquidation. PayMongo GCash payments settle directly to the invoice.</p></div><button disabled={!unremitted || busy} onClick={() => void submitRemittance()} className="rounded-xl bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"><Send className="mr-2 inline h-4 w-4" />Submit remittance</button></section>
      <section className="overflow-x-auto rounded-2xl border bg-card"><div className="border-b px-4 py-3"><h2 className="font-semibold">Recent collectible invoices</h2><p className="text-xs text-muted-foreground">Outstanding invoices issued from the start of last month through today, including invoices due in the coming days.</p></div><table className="w-full text-sm"><thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground"><tr><th className="p-4">Client</th><th className="p-4">Address</th><th className="p-4">Due date</th><th className="p-4 text-right">Balance</th><th className="p-4">Invoice</th><th className="p-4 text-right">Action</th></tr></thead><tbody>{invoices.map((invoice) => <tr className="border-t" key={invoice.id}><td className="p-4 font-medium">{invoice.customer?.full_name}<small className="block text-muted-foreground">{invoice.customer?.account_number}</small></td><td className="p-4">{invoice.customer?.address || 'To be updated'}</td><td className="p-4">{invoice.due_date.slice(0, 10)}</td><td className="p-4 text-right font-semibold">{peso(invoice.balance)}</td><td className="p-4">{invoice.invoice_number}{Number(invoice.previous_balance || 0) > 0 && <small className="block text-amber-700">Previous balance: {peso(Number(invoice.previous_balance))}</small>}</td><td className="p-4 text-right"><button onClick={() => openPayment(invoice)} className="rounded-lg bg-primary px-3 py-1.5 text-primary-foreground">Receive payment</button></td></tr>)}{!invoices.length && <tr><td className="p-10 text-center text-muted-foreground" colSpan={6}>No collectible invoices were issued in this period.</td></tr>}</tbody></table></section>
    </> : <><section className="grid gap-3 rounded-2xl border bg-card p-4 sm:grid-cols-2">
      <label className="text-sm font-medium">Month<div className="mt-1 flex gap-2"><input type="month" value={remittanceMonth} onChange={(event) => setRemittanceMonth(event.target.value)} className="min-w-0 flex-1 rounded-lg border bg-background px-3 py-2" /><button type="button" disabled={!remittanceMonth} onClick={() => setRemittanceMonth('')} className="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">All months</button></div><span className="mt-1 block text-xs text-muted-foreground">Choose a month or show the complete remittance history.</span></label>
      <label className="text-sm font-medium">Sort by date<select value={remittanceDateOrder} onChange={(event) => setRemittanceDateOrder(event.target.value as 'newest' | 'oldest')} className="mt-1 w-full rounded-lg border bg-background px-3 py-2"><option value="newest">Newest to oldest</option><option value="oldest">Oldest to newest</option></select><span className="mt-1 block text-xs text-muted-foreground">Uses the remittance submission date and time.</span></label>
    </section><section className="space-y-3">{remittances.map((item) => {
      const totals = paymentTotals(item);
      const liquidated = Boolean(item.liquidated_at && item.liquidated_by);
      const matches = liquidated && Math.round(Number(item.cash_counted_amount || 0) * 100) === Math.round(Number(totals.cash || 0) * 100);
      return <article key={item.id} className="rounded-2xl border bg-card p-5">
        <div className="flex flex-col gap-4 md:flex-row md:justify-between"><div><h2 className="font-semibold">{item.collector?.name || 'Collector'} · {peso(item.declared_amount)}</h2><p className="mt-1 text-sm capitalize text-muted-foreground">{item.status} · submitted {dateTime(item.submitted_at)}</p><div className="mt-3 flex flex-wrap gap-3 text-xs text-muted-foreground"><span>Cash: {peso(totals.cash || 0)}</span><span>GCash: {peso(totals.mobile_money || 0)}</span><span>Bank: {peso(totals.bank_transfer || 0)}</span></div></div><div className="text-sm md:text-right"><p>Liquidated by <strong>{item.liquidator?.name || 'Awaiting cashier'}</strong></p><p className="text-xs text-muted-foreground">{dateTime(item.liquidated_at)}</p>{item.receiver && <><p className="mt-2">Received by <strong>{item.receiver.name}</strong></p><p className="text-xs text-muted-foreground">{dateTime(item.received_at)}</p></>}</div></div>
        <div className={`mt-4 rounded-xl p-3 text-sm ${matches ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'}`}><strong>Cash liquidation:</strong> {liquidated ? `${peso(Number(item.cash_counted_amount))} counted ${matches ? 'matches recorded cash.' : `does not match ${peso(totals.cash || 0)}.`}` : 'Awaiting administrator/cashier cash count.'}</div>
        {item.cash_breakdown && <div className="mt-3 flex flex-wrap gap-2 text-xs text-muted-foreground">{item.cash_breakdown.filter((line) => line.count > 0).map((line) => <span key={`${line.denomination}-${line.kind}`} className="rounded bg-muted px-2 py-1">{line.count} × ₱{line.denomination} = {peso(line.amount)}</span>)}</div>}
        <div className="mt-4 flex flex-wrap gap-2"><button onClick={() => setDetailsTarget(item)} className="rounded-lg border border-primary/30 bg-primary/5 px-4 py-2 text-sm font-semibold text-primary"><Eye className="mr-2 inline h-4 w-4" />View details ({item.payments?.length || 0})</button>{item.status === 'submitted' && !liquidated && <button onClick={() => { setLiquidationTarget(item); setCounts({}); }} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"><Calculator className="mr-2 inline h-4 w-4" />Liquidate cash</button>}{item.status === 'submitted' && liquidated && <button disabled={!matches} onClick={() => { setVerifyTarget(item); setReceivedAmount(String(item.declared_amount)); }} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50"><CheckCircle2 className="mr-2 inline h-4 w-4" />Validate received remittance</button>}</div>
      </article>;
    })}{!remittances.length && <section className="rounded-2xl border bg-card p-8 text-center text-muted-foreground">No remittances found for this month.</section>}</section></>}
    {paymentInvoice && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"><div className="w-full max-w-md rounded-2xl bg-card shadow-2xl"><div className="flex justify-between border-b p-5"><div><h2 className="font-bold">Receive payment</h2><p className="text-sm text-muted-foreground">{paymentInvoice.customer?.full_name} · {paymentInvoice.invoice_number}</p></div><button onClick={closePayment}><X /></button></div><div className="space-y-4 p-5"><p className="rounded-xl bg-muted p-3 text-lg font-bold">Balance: {peso(paymentInvoice.balance)}</p><div className="grid grid-cols-3 gap-2"><button onClick={() => { setPaymentMethod('cash'); setCheckout(null); setQrPayment(null); setQrCode(''); }} className={`rounded-xl border p-3 ${paymentMethod === 'cash' ? 'border-primary bg-primary/10' : ''}`}><Banknote className="mx-auto" />Cash</button><button onClick={() => setPaymentMethod('mobile_money')} className={`rounded-xl border p-3 ${paymentMethod === 'mobile_money' ? 'border-primary bg-primary/10' : ''}`}><Smartphone className="mx-auto" />GCash / QR Ph</button><button onClick={() => { setPaymentMethod('bank_transfer'); setCheckout(null); setQrPayment(null); setQrCode(''); }} className={`rounded-xl border p-3 ${paymentMethod === 'bank_transfer' ? 'border-primary bg-primary/10' : ''}`}><Landmark className="mx-auto" />Bank</button></div>{paymentMethod === 'mobile_money' ? <section className="space-y-4 rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 text-center"><div><h3 className="font-semibold text-emerald-950">PayMongo QR Ph</h3><p className="mt-1 text-xs text-emerald-900">Dynamic QR tied to {paymentInvoice.customer?.account_number} and this invoice. Payment is recorded only after PayMongo confirmation.</p></div>{qrCode && <img src={qrCode} alt="PayMongo QR Ph payment code" className="mx-auto h-56 w-56 rounded-xl bg-white p-2" />}{qrPayment && <><p className="text-xs text-muted-foreground">Reference: {qrPayment.reference_number}</p><button disabled={busy} onClick={() => void reconcileGcashCheckout()} className="w-full rounded-xl border border-emerald-600 px-4 py-3 font-semibold text-emerald-800">Check QR Ph payment status</button></>}{checkout && <><p className="text-xs text-muted-foreground">Online checkout reference: {checkout.reference_number}</p><a href={checkout.checkout_url} target="_blank" rel="noreferrer" className="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white"><ExternalLink className="h-4 w-4" />Open online GCash checkout</a><button disabled={busy} onClick={() => void reconcileGcashCheckout()} className="w-full rounded-xl border border-emerald-600 px-4 py-3 font-semibold text-emerald-800">Check checkout status</button></>}{!qrPayment && !checkout && <div className="grid gap-2"><button disabled={busy} onClick={() => void startQrPhPayment()} className="w-full rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white disabled:opacity-50">{busy ? 'Generating PayMongo QR…' : 'Generate PayMongo QR Ph'}</button><button disabled={busy} onClick={() => void startGcashCheckout()} className="w-full rounded-xl border border-emerald-600 px-4 py-3 font-semibold text-emerald-800">Open online GCash checkout</button></div>}</section> : <><label className="block text-sm">Amount received<input type="number" min="0.01" max={paymentInvoice.balance} value={paymentAmount} onChange={(event) => setPaymentAmount(event.target.value)} className="mt-1 w-full rounded-lg border p-2" /></label>{paymentMethod === 'bank_transfer' && <label className="block text-sm">Bank reference<input value={reference} onChange={(event) => setReference(event.target.value)} className="mt-1 w-full rounded-lg border p-2" /></label>}<button disabled={busy} onClick={() => void recordPayment()} className="w-full rounded-xl bg-primary px-4 py-3 font-semibold text-primary-foreground">Confirm payment</button></>}</div></div></div>}
    {liquidationTarget && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"><div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-card shadow-2xl"><div className="flex justify-between border-b p-5"><div><h2 className="flex items-center gap-2 text-lg font-bold"><Calculator className="text-primary" />Cash liquidation</h2><p className="mt-1 text-sm text-muted-foreground">Count the physical cash received from {liquidationTarget.collector?.name || 'the collector'}.</p></div><button onClick={() => setLiquidationTarget(null)}><X /></button></div><div className="space-y-4 p-5"><div className="grid grid-cols-2 gap-3"><div className="rounded-xl bg-muted p-3"><p className="text-xs text-muted-foreground">Recorded cash</p><p className="text-xl font-bold">{peso(cashExpected)}</p></div><div className={`rounded-xl p-3 ${cashCounted >= cashExpected ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'}`}><p className="text-xs">Cash counted</p><p className="text-xl font-bold">{peso(cashCounted)}</p><p className="text-xs">{cashMatches ? 'Amounts match' : cashCounted > cashExpected ? `Excess: ${peso(cashCounted - cashExpected)} — accepted as cash overage` : `Shortage: ${peso(cashExpected - cashCounted)}`}</p></div></div><table className="w-full text-sm"><thead className="text-left text-xs uppercase text-muted-foreground"><tr><th className="pb-2">No. of pcs</th><th className="pb-2">Denomination</th><th className="pb-2 text-right">Amount</th></tr></thead><tbody>{DENOMINATIONS.map(({ denomination, kind }) => { const key = denominationKey(denomination, kind); return <tr className="border-t" key={key}><td className="py-2"><input min="0" type="number" value={counts[key] || ''} onChange={(event) => setCounts((current) => ({ ...current, [key]: Math.max(0, Number(event.target.value) || 0) }))} className="w-28 rounded-lg border bg-background px-3 py-2" /></td><td className="py-2 font-medium">₱{denomination.toLocaleString('en-PH')} {kind}</td><td className="py-2 text-right font-semibold">{peso(denomination * Number(counts[key] || 0))}</td></tr>; })}</tbody></table>{cashShortage && <div className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950"><p className="font-bold">Short remittance requires documented fuel expense</p><label className="block text-sm">Reason<select value={shortageReason} onChange={(event) => setShortageReason(event.target.value)} className="mt-1 w-full rounded-lg border bg-white p-2 text-gray-950"><option value="">Select reason</option><option value="travel_expense_gas">Travel Expense — Gas</option></select></label><label className="block text-sm">Official receipt number/reference<input value={expenseReceiptReference} onChange={(event) => setExpenseReceiptReference(event.target.value)} className="mt-1 w-full rounded-lg border bg-white p-2 text-gray-950" /></label><label className="block text-sm">Official gas receipt image or PDF<input type="file" accept="image/jpeg,image/png,application/pdf" onChange={(event) => setExpenseReceipt(event.target.files?.[0] || null)} className="mt-1 block w-full text-sm" /></label><p className="text-xs">The shortage will be recorded automatically under Daily Operations → Travel Expenses.</p></div>}<button disabled={busy || !liquidationReady} onClick={() => void liquidate()} className="w-full rounded-xl bg-primary px-4 py-3 font-semibold text-primary-foreground disabled:opacity-50"><Calculator className="mr-2 inline h-4 w-4" />{busy ? 'Saving…' : 'Confirm cash liquidation'}</button></div></div></div>}
    {verifyTarget && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"><div className="w-full max-w-md rounded-2xl bg-card shadow-2xl"><div className="flex justify-between border-b p-5"><div><h2 className="font-bold">Validate remittance</h2><p className="text-sm text-muted-foreground">Cash has been liquidated. Record the total remittance received.</p></div><button onClick={() => setVerifyTarget(null)}><X /></button></div><div className="space-y-4 p-5"><p className="rounded-xl bg-emerald-50 p-3 text-lg font-bold text-emerald-900">Declared total: {peso(verifyTarget.declared_amount)}</p><label className="block text-sm">Amount received<input type="number" min="0" step="0.01" value={receivedAmount} onChange={(event) => setReceivedAmount(event.target.value)} className="mt-1 w-full rounded-lg border p-2" /></label><button disabled={busy} onClick={() => void validate()} className="w-full rounded-xl bg-primary px-4 py-3 font-semibold text-primary-foreground"><CheckCircle2 className="mr-2 inline h-4 w-4" />Validate received remittance</button></div></div></div>}
    {paymentInvoice && paymentMethod === 'cash' && cashStep === 'signature' && <aside className="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4"><section className="w-full max-w-md rounded-2xl border border-primary/30 bg-card p-5 shadow-2xl"><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-semibold uppercase tracking-wide text-primary">Step 2 of 2 · Security confirmation</p><h3 className="mt-1 text-lg font-semibold">Cash payment signature</h3><p className="mt-1 text-xs text-muted-foreground">The first client signature becomes the protected reference on their profile. Future client signatures require at least a 50% match.</p></div><span className={`rounded-full px-2 py-1 text-xs ${signaturePresent ? 'bg-emerald-100 text-emerald-900' : 'bg-amber-100 text-amber-900'}`}>{signaturePresent ? 'Captured' : 'Required'}</span></div><label className="mt-4 flex cursor-pointer items-center gap-2 rounded-lg bg-muted/60 p-3 text-sm"><input type="checkbox" checked={familySigner} onChange={(event) => setFamilySigner(event.target.checked)} />Client is unavailable; authorized person signs</label>{familySigner && <label className="mt-3 block text-sm">Authorized person’s name <span className="text-muted-foreground">(optional)</span><input value={familySignerName} onChange={(event) => setFamilySignerName(event.target.value)} placeholder="Optional name" className="mt-1 w-full rounded-lg border bg-background p-2" /></label>}<div className="mt-4 rounded-lg border bg-white"><canvas ref={signatureCanvas} width={640} height={240} onPointerDown={startSignature} onPointerMove={drawSignature} onPointerUp={endSignature} onPointerLeave={endSignature} className="h-36 w-full touch-none cursor-crosshair" aria-label="Signature pad" /></div><div className="mt-3 flex items-center justify-between"><button type="button" onClick={() => setCashStep('details')} className="text-sm font-medium text-muted-foreground">Back to payment details</button><button type="button" onClick={clearSignature} className="text-sm font-medium text-primary">Clear signature</button></div><button disabled={busy || !signaturePresent} onClick={() => void recordPayment()} className="mt-4 w-full rounded-xl bg-primary px-4 py-3 font-semibold text-primary-foreground disabled:opacity-50">Confirm cash payment</button></section></aside>}
  </main></DashboardLayout>;
}

function RemittanceDetailsModal({ remittance, onClose }: { remittance: Remittance; onClose: () => void }) {
  const payments = remittance.payments || [];
  const total = payments.reduce((sum, payment) => sum + Number(payment.amount), 0);

  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-3 sm:p-4" role="dialog" aria-modal="true" aria-label="Remittance collection details">
    <div className="max-h-[92vh] w-full max-w-5xl overflow-hidden rounded-2xl border bg-card shadow-2xl">
      <div className="flex items-start justify-between gap-4 border-b p-4 sm:p-5"><div><h2 className="flex items-center gap-2 text-lg font-bold"><Eye className="text-primary" />Remittance details</h2><p className="mt-1 text-sm text-muted-foreground">{remittance.collector?.name || 'Collector'} · {peso(remittance.declared_amount)} · {payments.length} collected client{payments.length === 1 ? '' : 's'}</p></div><button onClick={onClose} aria-label="Close remittance details" className="rounded-lg p-1 hover:bg-muted"><X /></button></div>
      <div className="max-h-[calc(92vh-88px)] overflow-auto p-4 sm:p-5">
        <div className="mb-4 grid gap-3 sm:grid-cols-3"><div className="rounded-xl bg-muted p-3"><p className="text-xs text-muted-foreground">Submitted</p><p className="mt-1 font-semibold">{dateTime(remittance.submitted_at)}</p></div><div className="rounded-xl bg-muted p-3"><p className="text-xs text-muted-foreground">Liquidated</p><p className="mt-1 font-semibold">{dateTime(remittance.liquidated_at)}</p><p className="text-xs text-muted-foreground">{remittance.liquidator?.name || 'Not yet liquidated'}</p></div><div className="rounded-xl bg-muted p-3"><p className="text-xs text-muted-foreground">Status</p><p className="mt-1 font-semibold capitalize">{remittance.status}</p></div></div>
        <div className="overflow-x-auto rounded-xl border"><table className="min-w-[850px] w-full text-sm"><thead className="bg-muted/60 text-left text-xs uppercase text-muted-foreground"><tr><th className="p-3">Client</th><th className="p-3">Address</th><th className="p-3">Payment</th><th className="p-3">Invoice allocation</th><th className="p-3">Collected</th><th className="p-3 text-right">Amount</th></tr></thead><tbody>
          {payments.map((payment) => <tr key={payment.payment_number} className="border-t align-top"><td className="p-3 font-medium">{payment.customer?.full_name || 'Customer record unavailable'}<small className="block text-muted-foreground">{payment.customer?.account_number || 'No account number'}</small></td><td className="max-w-[220px] p-3 text-muted-foreground">{payment.customer?.address || 'Address not recorded'}</td><td className="p-3"><span className="capitalize">{payment.payment_method.replace('_', ' ')}</span><small className="block text-muted-foreground">{payment.payment_number}</small>{payment.reference && <small className="block text-muted-foreground">Ref: {payment.reference}</small>}</td><td className="p-3">{payment.allocations?.length ? payment.allocations.map((allocation, index) => <span key={`${payment.payment_number}-${allocation.invoice?.invoice_number || index}`} className="block">{allocation.invoice?.invoice_number || 'Invoice unavailable'} · {peso(allocation.amount)}</span>) : (payment.invoice?.invoice_number || 'Not linked')}</td><td className="whitespace-nowrap p-3">{dateTime(payment.payment_date)}</td><td className="p-3 text-right font-bold">{peso(payment.amount)}</td></tr>)}
          {!payments.length && <tr><td colSpan={6} className="p-10 text-center text-muted-foreground">No payments are attached to this remittance.</td></tr>}
        </tbody><tfoot><tr className="border-t bg-muted/40 font-bold"><td colSpan={5} className="p-3 text-right">Total collected</td><td className="p-3 text-right">{peso(total)}</td></tr></tfoot></table></div>
      </div>
    </div>
  </div>;
}
