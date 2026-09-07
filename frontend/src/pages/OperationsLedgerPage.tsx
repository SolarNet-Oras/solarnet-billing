import { useEffect, useMemo, useState } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import { formatPHP } from '@/lib/currency';
import { useAuth } from '@/hooks/useAuth';

type Entry = {
  id: string; description?: string; category?: string; amount: number; payment_method: string;
  customer?: { full_name: string; account_number: string };
  collector?: { name: string };
  receiver?: { name: string };
  recorder?: { name: string };
  remittance?: { liquidated_at?: string | null; liquidator?: { name: string } };
};
type Wallet = { collections: number; processing_fees: number; cash_in: number; transfers_in: number; transfers_out: number; expenses: number; balance: number };
type Data = { collections: Entry[]; cash_in: Entry[]; transfers: Entry[]; expenses: Entry[]; wallets: Record<'cash' | 'gcash' | 'bpi' | 'landbank', Wallet>; wallet_balance_as_of?: string };
type Definition = { id: string; type: string; description: string; payment_method: string; effect_type?: string | null; source_wallet?: string | null; destination_wallet?: string | null; active?: boolean };

const METHOD_LABELS: Record<string, string> = {
  cash: 'Cash', gcash: 'GCash', bank_bpi: 'BPI', bank_landbank: 'Landbank',
  add_to_cash: 'Add to Cash', add_to_gcash: 'Add to GCash',
  deposit_to_bpi: 'Deposit to BPI', deposit_to_landbank: 'Deposit to Landbank',
};

export default function OperationsLedgerPage(): React.JSX.Element {
  const { user } = useAuth();
  const roleNames = [user?.role, ...(user?.roles || []).map((item) => typeof item === 'string' ? item : item.name)].filter(Boolean);
  const canTopUpCash = roleNames.includes('super_admin');
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [periodMode, setPeriodMode] = useState<'day' | 'month'>('day');
  const [data, setData] = useState<Data | null>(null);
  const [definitions, setDefinitions] = useState<Definition[]>([]);
  const [type, setType] = useState('');
  const [description, setDescription] = useState('');
  const [definitionId, setDefinitionId] = useState('');
  const [amount, setAmount] = useState('');
  const [reference, setReference] = useState('');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState('');
  const [masterOpen, setMasterOpen] = useState(false);
  const [masterForm, setMasterForm] = useState({ type: '', description: '', payment_method: 'cash', source_wallet: '' });
  const [isSavingMaster, setIsSavingMaster] = useState(false);
  const [showCashTopUp, setShowCashTopUp] = useState(false);
  const [topUp, setTopUp] = useState({ destination_wallet: 'cash', amount: '', reference: '', notes: '', confirmation: '' });
  const [isSavingTopUp, setIsSavingTopUp] = useState(false);

  const activeDefinitions = useMemo(() => definitions.filter((definition) => definition.active !== false && (canTopUpCash || !(definition.effect_type === 'cash_in' && !definition.source_wallet && ['cash', 'gcash', 'bpi', 'landbank'].includes(String(definition.destination_wallet))))), [definitions, canTopUpCash]);
  const types = useMemo(() => [...new Set(activeDefinitions.map((definition) => definition.type))], [activeDefinitions]);
  const descriptions = useMemo(() => [...new Set(activeDefinitions.filter((definition) => definition.type === type).map((definition) => definition.description))], [activeDefinitions, type]);
  const paymentOptions = useMemo(() => activeDefinitions.filter((definition) => definition.type === type && definition.description === description), [activeDefinitions, type, description]);

  const load = async (): Promise<void> => {
    try {
      const periodParams = periodMode === 'month' ? { month } : { date };
      const [ledger, master] = await Promise.all([api.get('/financial-entries', { params: periodParams }), api.get('/transaction-definitions', { params: { include_inactive: masterOpen } })]);
      setData(ledger.data.data);
      setDefinitions(master.data.data);
    } catch { setError('Could not load the daily ledger.'); }
  };
  useEffect(() => { void load(); }, [date, month, periodMode, masterOpen]);

  const save = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    if (isSaving || !definitionId) return;
    setError(''); setIsSaving(true);
    try {
      await api.post('/financial-entries', { transaction_definition_id: definitionId, amount: Number(amount), entry_date: date, reference: reference || null, idempotency_key: crypto.randomUUID() });
      setType(''); setDescription(''); setDefinitionId(''); setAmount(''); setReference('');
      await load();
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not save this record.'); }
    finally { setIsSaving(false); }
  };

  const saveMaster = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    if (isSavingMaster) return;
    setError(''); setIsSavingMaster(true);
    try {
      await api.post('/transaction-definitions', { ...masterForm, source_wallet: masterForm.source_wallet || null });
      setMasterForm({ type: '', description: '', payment_method: 'cash', source_wallet: '' });
      await load();
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not save this dropdown option.'); }
    finally { setIsSavingMaster(false); }
  };

  const deactivateMaster = async (definition: Definition): Promise<void> => {
    if (!window.confirm(`Remove “${definition.type} → ${definition.description} → ${METHOD_LABELS[definition.payment_method] ?? definition.payment_method}” from future dropdowns? Historical records will be kept.`)) return;
    setError('');
    try { await api.delete(`/transaction-definitions/${definition.id}`); await load(); }
    catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not remove this dropdown option.'); }
  };

  const saveCashTopUp = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    if (isSavingTopUp || topUp.confirmation !== 'ADD WALLET TOP UP') return;
    setError(''); setIsSavingTopUp(true);
    try {
      const response = await api.post('/financial-entries/cash-top-up', {
        destination_wallet: topUp.destination_wallet,
        amount: Number(topUp.amount), entry_date: date, reference: topUp.reference.trim(), notes: topUp.notes.trim(),
        confirmation: topUp.confirmation, idempotency_key: crypto.randomUUID(),
      });
      setTopUp({ destination_wallet: 'cash', amount: '', reference: '', notes: '', confirmation: '' });
      setShowCashTopUp(false);
      await load();
      window.alert(response.data?.message || 'Cash top-up recorded.');
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not record the cash top-up.'); }
    finally { setIsSavingTopUp(false); }
  };

  const list = (items: Entry[], empty: string) => <div className="mt-3 divide-y divide-border text-sm">{items.length ? items.map((item) => {
    const actor = item.remittance?.liquidator?.name
      ? `Liquidated by ${item.remittance.liquidator.name}`
      : item.collector?.name
        ? `Collected by ${item.collector.name}`
        : item.receiver?.name
          ? `Received by ${item.receiver.name}`
        : item.recorder?.name
          ? `Recorded by ${item.recorder.name}`
          : item.payment_method === 'mobile_money' ? 'Online payment' : 'System record';
    return <div key={item.id} className="flex justify-between gap-3 py-2"><span><b>{item.category || item.customer?.full_name || 'Client collection'}</b><span className="ml-2">{item.description || item.customer?.account_number || ''}</span><span className="ml-2 text-muted-foreground">{METHOD_LABELS[item.payment_method] ?? item.payment_method}</span><small className="mt-1 block text-xs text-muted-foreground">{actor}</small></span><b>{formatPHP(item.amount)}</b></div>;
  }) : <p className="py-3 text-muted-foreground">{empty}</p>}</div>;
  const wallets: Array<['cash' | 'gcash' | 'bpi' | 'landbank', string, string]> = [['cash', 'Cash', 'text-emerald-600'], ['gcash', 'GCash', 'text-violet-600'], ['bpi', 'BPI', 'text-blue-600'], ['landbank', 'Landbank', 'text-cyan-600']];

  return <DashboardLayout><main className="mx-auto max-w-6xl space-y-6">
    <div className="flex flex-wrap justify-between gap-3"><div><h1 className="text-3xl font-bold text-foreground">Daily Operations</h1><p className="mt-1 text-muted-foreground">{periodMode === 'month' ? `Viewing all transactions for ${month}.` : `Viewing transactions for ${date}.`} New records use the selected date.</p></div><div className="flex flex-wrap gap-2">{canTopUpCash && <button type="button" onClick={() => setShowCashTopUp((open) => !open)} className="self-end rounded-lg bg-amber-500 px-3 py-2 text-sm font-bold text-slate-950 shadow-sm hover:bg-amber-400">{showCashTopUp ? 'Cancel wallet top-up' : 'Top up Cash / GCash / Banks'}</button>}<button type="button" onClick={() => setMasterOpen((open) => !open)} className="rounded-lg border border-input bg-background px-3 py-2 text-sm font-medium text-foreground">{masterOpen ? 'Close dropdown settings' : 'Manage dropdowns'}</button><label className="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white shadow-sm">Specific date<input type="date" value={date} onChange={(event) => { setDate(event.target.value); setPeriodMode('day'); }} className="mt-1 block w-full cursor-pointer rounded-md border border-blue-300 bg-white px-2 py-1.5 text-sm font-semibold text-blue-950 [color-scheme:light]" /></label><label className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white shadow-sm">Month<input type="month" value={month} onChange={(event) => { setMonth(event.target.value); setPeriodMode('month'); }} className="mt-1 block w-full cursor-pointer rounded-md border border-emerald-300 bg-white px-2 py-1.5 text-sm font-semibold text-emerald-950 [color-scheme:light]" /></label><button type="button" onClick={() => { setDate(new Date().toISOString().slice(0, 10)); setPeriodMode('day'); }} className="self-end rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Today</button></div></div>
    {error && <p className="rounded-lg bg-red-50 p-3 text-red-700">{error}</p>}
    {canTopUpCash && showCashTopUp && <form onSubmit={saveCashTopUp} className="rounded-2xl border border-amber-300 bg-amber-50 p-5 dark:border-amber-700 dark:bg-amber-950/30"><h2 className="font-bold text-amber-950 dark:text-amber-100">Super Administrator wallet top-up</h2><p className="mt-1 text-sm text-amber-900 dark:text-amber-200">Adds new funds to Cash, GCash, BPI, or Landbank as a permanent, attributed ledger entry. This is not a customer collection or internal transfer.</p><div className="mt-4 grid gap-3 sm:grid-cols-2"><label className="text-xs font-semibold">Destination wallet *<select required value={topUp.destination_wallet} onChange={(e) => setTopUp({ ...topUp, destination_wallet: e.target.value })} className="mt-1 w-full rounded-lg border border-amber-300 bg-white p-2 text-gray-950"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bpi">BPI</option><option value="landbank">Landbank</option></select></label><label className="text-xs font-semibold">Amount<input required min="0.01" step="0.01" type="number" value={topUp.amount} onChange={(e) => setTopUp({ ...topUp, amount: e.target.value })} className="mt-1 w-full rounded-lg border border-amber-300 bg-white p-2 text-gray-950" /></label><label className="text-xs font-semibold sm:col-span-2">Source reference / receipt *<input required value={topUp.reference} onChange={(e) => setTopUp({ ...topUp, reference: e.target.value })} className="mt-1 w-full rounded-lg border border-amber-300 bg-white p-2 text-gray-950" placeholder="Owner funding receipt, GCash reference, deposit slip, or bank reference" /></label><label className="text-xs font-semibold sm:col-span-2">Reason / source of funds *<textarea required minLength={3} value={topUp.notes} onChange={(e) => setTopUp({ ...topUp, notes: e.target.value })} className="mt-1 w-full rounded-lg border border-amber-300 bg-white p-2 text-gray-950" rows={2} /></label><label className="text-xs font-semibold sm:col-span-2">Type ADD WALLET TOP UP to confirm<input required value={topUp.confirmation} onChange={(e) => setTopUp({ ...topUp, confirmation: e.target.value })} className="mt-1 w-full rounded-lg border border-amber-300 bg-white p-2 text-gray-950" /></label><button disabled={isSavingTopUp || topUp.confirmation !== 'ADD WALLET TOP UP'} className="rounded-lg bg-amber-500 p-2 font-bold text-slate-950 disabled:cursor-not-allowed disabled:opacity-50 sm:col-span-2">{isSavingTopUp ? 'Recording…' : 'Record wallet top-up'}</button></div></form>}
    <section><p className="mb-2 text-xs text-muted-foreground">Overall wallet balances through {data?.wallet_balance_as_of || 'today'}; previous months remain included even when the transaction list is filtered.</p><div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{wallets.map(([key, name, color]) => { const wallet = data?.wallets?.[key] ?? { collections: 0, processing_fees: 0, cash_in: 0, transfers_in: 0, transfers_out: 0, expenses: 0, balance: 0 }; return <article key={key} className="rounded-2xl border border-border bg-card p-5"><p className="text-sm text-muted-foreground">Overall {name} balance</p><p className={`mt-2 text-2xl font-bold ${color}`}>{formatPHP(wallet.balance)}</p><p className="mt-2 text-xs text-muted-foreground">All collections {formatPHP(wallet.collections)} · Provider fees {formatPHP(wallet.processing_fees)} · All in {formatPHP(wallet.cash_in + wallet.transfers_in)} · All out {formatPHP(wallet.expenses + wallet.transfers_out)}</p>{key === 'cash' && <p className="mt-2 text-[11px] text-emerald-700 dark:text-emerald-400">Collector cash is added only after cashier liquidation.</p>}</article>; })}</div></section>
    {masterOpen && <section className="rounded-2xl border border-border bg-card p-5"><div><h2 className="font-semibold text-foreground">Transaction dropdown settings</h2><p className="mt-1 text-sm text-muted-foreground">Add one allowed Type → Description → Payment Method combination. Remove only deactivates it; saved financial records are never deleted.</p></div><div className="mt-5 grid gap-6 lg:grid-cols-2"><form onSubmit={saveMaster} className="grid gap-3 sm:grid-cols-2"><label className="text-xs font-semibold text-muted-foreground">Type<input required value={masterForm.type} onChange={(event) => setMasterForm({ ...masterForm, type: event.target.value })} placeholder="e.g. CCTV Supplies" className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-foreground" /></label><label className="text-xs font-semibold text-muted-foreground">Description<input required value={masterForm.description} onChange={(event) => setMasterForm({ ...masterForm, description: event.target.value })} placeholder="e.g. Installation" className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-foreground" /></label><label className="text-xs font-semibold text-muted-foreground">Payment method<select value={masterForm.payment_method} onChange={(event) => setMasterForm({ ...masterForm, payment_method: event.target.value })} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-foreground"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bank_bpi">BPI</option><option value="bank_landbank">Landbank</option><option value="add_to_cash">Add to Cash</option><option value="add_to_gcash">Add to GCash</option><option value="deposit_to_bpi">Deposit to BPI</option><option value="deposit_to_landbank">Deposit to Landbank</option></select></label><label className="text-xs font-semibold text-muted-foreground">Source wallet <span className="font-normal">(Cash In transfer only)</span><select value={masterForm.source_wallet} onChange={(event) => setMasterForm({ ...masterForm, source_wallet: event.target.value })} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-foreground"><option value="">New cash in / no source</option><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bpi">BPI</option><option value="landbank">Landbank</option></select></label><button disabled={isSavingMaster} className="rounded-lg bg-primary p-2 font-semibold text-primary-foreground disabled:opacity-50 sm:col-span-2">{isSavingMaster ? 'Saving…' : 'Add dropdown option'}</button></form><div className="max-h-72 overflow-auto rounded-lg border border-border"><table className="w-full text-left text-xs"><thead className="sticky top-0 bg-card text-muted-foreground"><tr><th className="p-2">Type</th><th className="p-2">Description</th><th className="p-2">Method</th><th className="p-2">Status</th><th className="p-2"></th></tr></thead><tbody>{definitions.map((definition) => <tr key={definition.id} className="border-t border-border"><td className="p-2">{definition.type}</td><td className="p-2">{definition.description}</td><td className="p-2">{METHOD_LABELS[definition.payment_method] ?? definition.payment_method}</td><td className="p-2">{definition.active === false ? 'Inactive' : 'Active'}</td><td className="p-2">{definition.active !== false && <button type="button" onClick={() => void deactivateMaster(definition)} className="text-red-600 hover:underline">Remove</button>}</td></tr>)}</tbody></table></div></div></section>}
    <section className="grid gap-6 lg:grid-cols-3"><form onSubmit={save} className="rounded-2xl border border-border bg-card p-5"><h2 className="font-semibold text-foreground">Add daily record</h2><p className="mt-1 text-xs text-muted-foreground">Choose each field in order. Invalid combinations cannot be saved.</p><div className="mt-4 space-y-3">
      <label className="block text-xs font-semibold text-muted-foreground">Type of transaction<select required value={type} onChange={(event) => { setType(event.target.value); setDescription(''); setDefinitionId(''); }} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-sm text-foreground"><option value="">Select transaction type</option>{types.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
      <label className="block text-xs font-semibold text-muted-foreground">Description<select required disabled={!type} value={description} onChange={(event) => { setDescription(event.target.value); setDefinitionId(''); }} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-sm text-foreground disabled:opacity-50"><option value="">{type ? 'Select description' : 'Select a type first'}</option>{descriptions.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
      <label className="block text-xs font-semibold text-muted-foreground">Payment method<select required disabled={!description} value={definitionId} onChange={(event) => setDefinitionId(event.target.value)} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-sm text-foreground disabled:opacity-50"><option value="">{description ? 'Select payment method' : 'Select a description first'}</option>{paymentOptions.map((option) => <option key={option.id} value={option.id}>{METHOD_LABELS[option.payment_method] ?? option.payment_method}</option>)}</select></label>
      <label className="block text-xs font-semibold text-muted-foreground">Amount<input required min="0.01" step="0.01" type="number" value={amount} onChange={(event) => setAmount(event.target.value)} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-foreground" /></label>
      <label className="block text-xs font-semibold text-muted-foreground">Reference / OR number <span className="font-normal">(optional)</span><input value={reference} onChange={(event) => setReference(event.target.value)} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-foreground" /></label>
      <button disabled={isSaving || !definitionId} className="w-full rounded-lg bg-primary p-2 font-semibold text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50">{isSaving ? 'Saving…' : 'Save record'}</button>
    </div></form><article className="rounded-2xl border border-border bg-card p-5"><h2 className="font-semibold text-foreground">Expenses</h2>{list(data?.expenses ?? [], 'No expenses for this day.')}</article><article className="rounded-2xl border border-border bg-card p-5"><h2 className="font-semibold text-foreground">Cash in</h2>{list(data?.cash_in ?? [], 'No cash-in records for this day.')}</article></section>
    <section className="grid gap-6 lg:grid-cols-2"><article className="rounded-2xl border border-border bg-card p-5"><h2 className="font-semibold text-foreground">Internal transfers</h2>{list(data?.transfers ?? [], 'No internal transfers for this day.')}</article><article className="rounded-2xl border border-border bg-card p-5"><h2 className="font-semibold text-foreground">Client payment collections</h2>{list(data?.collections ?? [], 'No client collections for this day.')}</article></section>
  </main></DashboardLayout>;
}
