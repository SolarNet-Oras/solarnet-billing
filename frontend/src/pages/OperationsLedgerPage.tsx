import { useEffect, useMemo, useState } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import { formatPHP } from '@/lib/currency';

type Entry = { id: string; description: string; category?: string; amount: number; payment_method: string };
type Wallet = { collections: number; cash_in: number; transfers_in: number; transfers_out: number; expenses: number; balance: number };
type Data = { collections: Entry[]; cash_in: Entry[]; transfers: Entry[]; expenses: Entry[]; wallets: Record<'cash' | 'gcash' | 'bpi' | 'landbank', Wallet> };
type Definition = { id: string; type: string; description: string; payment_method: string };

const METHOD_LABELS: Record<string, string> = {
  cash: 'Cash', gcash: 'GCash', bank_bpi: 'BPI', bank_landbank: 'Landbank',
  add_to_cash: 'Add to Cash', add_to_gcash: 'Add to GCash',
  deposit_to_bpi: 'Deposit to BPI', deposit_to_landbank: 'Deposit to Landbank',
};

export default function OperationsLedgerPage(): React.JSX.Element {
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [data, setData] = useState<Data | null>(null);
  const [definitions, setDefinitions] = useState<Definition[]>([]);
  const [type, setType] = useState('');
  const [description, setDescription] = useState('');
  const [definitionId, setDefinitionId] = useState('');
  const [amount, setAmount] = useState('');
  const [reference, setReference] = useState('');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState('');

  const types = useMemo(() => [...new Set(definitions.map((definition) => definition.type))], [definitions]);
  const descriptions = useMemo(() => [...new Set(definitions.filter((definition) => definition.type === type).map((definition) => definition.description))], [definitions, type]);
  const paymentOptions = useMemo(() => definitions.filter((definition) => definition.type === type && definition.description === description), [definitions, type, description]);

  const load = async (): Promise<void> => {
    try {
      const [ledger, master] = await Promise.all([api.get('/financial-entries', { params: { date } }), api.get('/transaction-definitions')]);
      setData(ledger.data.data);
      setDefinitions(master.data.data);
    } catch { setError('Could not load the daily ledger.'); }
  };
  useEffect(() => { void load(); }, [date]);

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

  const list = (items: Entry[], empty: string) => <div className="mt-3 divide-y divide-border text-sm">{items.length ? items.map((item) => <div key={item.id} className="flex justify-between gap-3 py-2"><span><b>{item.category}</b><span className="ml-2">{item.description}</span><span className="ml-2 text-muted-foreground">{METHOD_LABELS[item.payment_method] ?? item.payment_method}</span></span><b>{formatPHP(item.amount)}</b></div>) : <p className="py-3 text-muted-foreground">{empty}</p>}</div>;
  const wallets: Array<['cash' | 'gcash' | 'bpi' | 'landbank', string, string]> = [['cash', 'Cash', 'text-emerald-600'], ['gcash', 'GCash', 'text-violet-600'], ['bpi', 'BPI', 'text-blue-600'], ['landbank', 'Landbank', 'text-cyan-600']];

  return <DashboardLayout><main className="mx-auto max-w-6xl space-y-6">
    <div className="flex flex-wrap justify-between gap-3"><div><h1 className="text-3xl font-bold text-foreground">Daily Operations</h1><p className="mt-1 text-muted-foreground">A validated transaction master controls every record and wallet effect.</p></div><input type="date" value={date} onChange={(event) => setDate(event.target.value)} className="rounded-lg border border-input bg-background px-3 py-2 text-foreground" /></div>
    {error && <p className="rounded-lg bg-red-50 p-3 text-red-700">{error}</p>}
    <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{wallets.map(([key, name, color]) => { const wallet = data?.wallets?.[key] ?? { collections: 0, cash_in: 0, transfers_in: 0, transfers_out: 0, expenses: 0, balance: 0 }; return <article key={key} className="rounded-2xl border border-border bg-card p-5"><p className="text-sm text-muted-foreground">{name} balance</p><p className={`mt-2 text-2xl font-bold ${color}`}>{formatPHP(wallet.balance)}</p><p className="mt-2 text-xs text-muted-foreground">Collections {formatPHP(wallet.collections)} · In {formatPHP(wallet.cash_in + wallet.transfers_in)} · Out {formatPHP(wallet.expenses + wallet.transfers_out)}</p></article>; })}</section>
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
