import { useEffect, useMemo, useState } from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import api from '@/services/api';
import { formatPHP } from '@/lib/currency';

type Entry = { id: string; description: string; category?: string; amount: number; payment_method: string };
type Wallet = { collections: number; sales: number; expenses: number; balance: number };
type Data = {
  collections: Array<Entry & { customer?: { full_name: string; account_number: string } }>;
  sales: Entry[];
  expenses: Entry[];
  wallets: Record<'cash' | 'ewallet' | 'bank', Wallet>;
};
type PaymentOption = readonly [string, string];

// Cash In is a transfer form. The selected source controls which destination can be selected.
const CASH_IN_DESTINATIONS: Record<string, PaymentOption[]> = {
  'From Cash': [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_gcash', 'Add to GCash']],
  'From GCash': [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash']],
  'From BPI': [['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  'Excess Fund': [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  Refund: [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  'Cash Return': [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  Vendo: [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  CCTV: [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  Solar: [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  Sales: [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  'From Landbank': [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  'Fiber Wire': [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  Credit: [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  Investment: [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
  Reconnection: [['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash']],
};

const TRANSACTION_TYPES: Record<string, string[]> = {
  'Cash In': Object.keys(CASH_IN_DESTINATIONS),
  'CCTV Supplies': ['Monitor', 'DVR/XVR/NVR', 'HDD', 'Camera', 'UTP', 'Media Converter', 'PoE Switch', 'Connector', 'Fiber Optic Cable', 'Switch', 'Outdoor Box', 'Terminating Box', 'Server Cabinet', 'Bracket', 'Accessories'],
  'Internet Supplies': ['Fiber', 'Modem', 'MikroTik', 'OLT', 'SC Connector', 'FBT', 'UPS', 'NAP Box', 'Cassette', 'F-Clamp', 'Straps', 'Buckle Lock', 'Retractor', 'Tools', 'Accessories', 'Server Tracks', 'Dead End Clamp', 'Computer Parts', 'Sleeve 40mm', 'Sleeve 60mm', 'Transceiver Modules', 'P-Clamp', 'ODF'],
  'Subscriptions Fees': ['Leased Line', 'SME', 'DIA', 'Rental', 'Loan', 'Mortgage', 'Starlink', 'Domain', 'Electric Bill'],
  'Office Supplies': ['Bond Paper', 'Ink', 'Folder', 'Pen', 'Thermal Paper', 'Portable Printer', 'Barcode Scanner', 'Uniform', 'Calculator', 'Marketing Supplies', 'Others'],
  'Solar Supplies': ['Panel', 'PV Wire', 'Inverter', 'Battery', 'Mounting Gear', 'Accessories'],
  'Travel Expenses': ['Chartered Boat', 'Fuel', 'Food', 'Water', 'Lodge', 'Fare'],
  Salary: ['Nelson Cuanico', 'Ricky Maestre', 'Luke Lombendencio', 'Ralph Aculana', 'Richard Phillip Aculana', 'Kirk Lowell Pajanustan', 'Niel Rio Desipeda'],
  'Permit and Licenses': ['NTC', "Mayor's Permit", 'Brgy. Permit', 'ESAMELCO'],
  'Professional Fees': ['Notarial Services', 'Book Keeper', 'Accountant', 'Others'],
  Taxes: ['LGU', 'BIR'],
  'Labor Expense': ['Contract', 'Daily Wage', 'Food', 'Water'],
  Miscellaneous: ['Others', 'Transaction Charge', 'Share Vendo', 'Shipping Fee', 'Material/Equipment'],
  'Repair and Maintenance': ['Change Oil', 'Tire', 'Gear Oil', 'Labor'],
  'Fiber Laying': ['Materials', 'Accessories'],
  Reimbursed: ['Ralph Aculana', 'Ricky Maestre', 'Nelson Cuanico', 'Richard Phillip Aculana', 'Dalmacio Pajanustan', 'Luke Lombendencio'],
  Refund: ['Ralph Aculana', 'Ricky Maestre', 'Nelson Cuanico', 'Richard Phillip Aculana', 'Dalmacio Pajanustan', 'Luke Lombendencio'],
  'Training Expenses': ['Registration Pay', 'Travel Expenses'],
};

const PAYMENT_METHODS: PaymentOption[] = [
  ['cash', 'Cash'], ['gcash', 'GCash'], ['bank_bpi', 'BPI / Bank'], ['bank_landbank', 'Landbank'], ['add_to_cash', 'Add to Cash'], ['add_to_gcash', 'Add to GCash'], ['deposit_to_bpi', 'Deposit to BPI'], ['deposit_to_landbank', 'Deposit to Landbank'], ['other', 'Other'],
];

export default function OperationsLedgerPage(): React.JSX.Element {
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [data, setData] = useState<Data | null>(null);
  const [category, setCategory] = useState('');
  const [description, setDescription] = useState('');
  const [customDescription, setCustomDescription] = useState(false);
  const [form, setForm] = useState({ amount: '', payment_method: 'cash' });
  const [error, setError] = useState('');
  const paymentOptions = useMemo(() => category === 'Cash In' ? (CASH_IN_DESTINATIONS[description] ?? []) : PAYMENT_METHODS, [category, description]);

  const load = async (): Promise<void> => {
    try { setData((await api.get('/financial-entries', { params: { date } })).data.data); }
    catch { setError('Could not load the daily ledger.'); }
  };
  useEffect(() => { void load(); }, [date]);

  const save = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    setError('');
    try {
      await api.post('/financial-entries', {
        type: category === 'Cash In' ? 'sale' : 'expense', category, description,
        amount: Number(form.amount), payment_method: form.payment_method, entry_date: date,
      });
      setCategory(''); setDescription(''); setCustomDescription(false); setForm({ amount: '', payment_method: 'cash' });
      await load();
    } catch (requestError: any) { setError(requestError.response?.data?.message || 'Could not save this entry.'); }
  };

  const list = (items: Entry[], empty: string) => (
    <div className="mt-3 divide-y divide-border text-sm">
      {items.length ? items.map((item) => <div key={item.id} className="flex justify-between gap-3 py-2"><span><b>{item.category}</b><span className="ml-2">{item.description}</span><span className="ml-2 capitalize text-muted-foreground">{item.payment_method}</span></span><b>{formatPHP(item.amount)}</b></div>) : <p className="py-3 text-muted-foreground">{empty}</p>}
    </div>
  );
  const wallets: Array<['cash' | 'ewallet' | 'bank', string, string]> = [['cash', 'Cash', 'text-emerald-600'], ['ewallet', 'E-Wallet / GCash', 'text-violet-600'], ['bank', 'Bank', 'text-blue-600']];

  return <DashboardLayout><main className="mx-auto max-w-6xl space-y-6">
    <div className="flex flex-wrap justify-between gap-3"><div><h1 className="text-3xl font-bold text-foreground">Daily Operations</h1><p className="mt-1 text-muted-foreground">Record daily income and expenses by wallet.</p></div><input type="date" value={date} onChange={(event) => setDate(event.target.value)} className="rounded-lg border border-input bg-background px-3 py-2 text-foreground" /></div>
    {error && <p className="rounded-lg bg-red-50 p-3 text-red-700">{error}</p>}
    <section className="grid gap-4 md:grid-cols-3">{wallets.map(([key, name, color]) => { const wallet = data?.wallets?.[key] ?? { collections: 0, sales: 0, expenses: 0, balance: 0 }; return <article key={key} className="rounded-2xl border border-border bg-card p-5"><p className="text-sm text-muted-foreground">{name} daily balance</p><p className={`mt-2 text-2xl font-bold ${color}`}>{formatPHP(wallet.balance)}</p><p className="mt-2 text-xs text-muted-foreground">+ Collection {formatPHP(wallet.collections)} · + Cash in {formatPHP(wallet.sales)} · − Expense {formatPHP(wallet.expenses)}</p></article>; })}</section>
    <section className="grid gap-6 lg:grid-cols-3"><form onSubmit={save} className="rounded-2xl border border-border bg-card p-5"><h2 className="font-semibold text-foreground">Add daily record</h2><div className="mt-4 space-y-3">
      <label className="block text-xs font-semibold text-muted-foreground">Type of transaction<select required value={category} onChange={(event) => { const nextCategory = event.target.value; setCategory(nextCategory); setDescription(''); setCustomDescription(false); setForm((current) => ({ ...current, payment_method: nextCategory === 'Cash In' ? '' : 'cash' })); }} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-sm text-foreground"><option value="">Select type</option>{Object.keys(TRANSACTION_TYPES).map((type) => <option key={type} value={type}>{type}</option>)}</select></label>
      <label className="block text-xs font-semibold text-muted-foreground">Description<select required disabled={!category} value={customDescription ? '__custom__' : description} onChange={(event) => { const nextDescription = event.target.value; if (nextDescription === '__custom__') { setCustomDescription(true); setDescription(''); setForm((current) => ({ ...current, payment_method: category === 'Cash In' ? '' : current.payment_method })); return; } setCustomDescription(false); setDescription(nextDescription); const firstDestination = CASH_IN_DESTINATIONS[nextDescription]?.[0]?.[0]; setForm((current) => ({ ...current, payment_method: category === 'Cash In' ? (firstDestination ?? '') : current.payment_method })); }} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-sm text-foreground disabled:opacity-50"><option value="">Select description</option>{(TRANSACTION_TYPES[category] ?? []).map((item) => <option key={item} value={item}>{item}</option>)}{category !== 'Cash In' && <option value="__custom__">Other / custom description</option>}</select></label>
      {customDescription && <input required value={description} onChange={(event) => setDescription(event.target.value)} placeholder="Custom description" className="w-full rounded-lg border border-input bg-background p-2" />}
      <label className="block text-xs font-semibold text-muted-foreground">Amount<input required min="0.01" step="0.01" type="number" value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-foreground" /></label>
      <label className="block text-xs font-semibold text-muted-foreground">Payment method<select required disabled={category === 'Cash In' && !description} value={form.payment_method} onChange={(event) => setForm({ ...form, payment_method: event.target.value })} className="mt-1 w-full rounded-lg border border-input bg-background p-2 text-sm text-foreground disabled:opacity-50">{!paymentOptions.length && <option value="">Select a Cash In description first</option>}{paymentOptions.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
      <button className="w-full rounded-lg bg-primary p-2 font-semibold text-primary-foreground">Save record</button>
    </div></form><article className="rounded-2xl border border-border bg-card p-5"><h2 className="font-semibold text-foreground">Expenses</h2>{list(data?.expenses ?? [], 'No expenses for this day.')}</article><article className="rounded-2xl border border-border bg-card p-5"><h2 className="font-semibold text-foreground">Cash in</h2>{list(data?.sales ?? [], 'No cash-in records for this day.')}</article></section>
    <section className="rounded-2xl border border-border bg-card p-5"><h2 className="font-semibold text-foreground">Client payment collections</h2>{list(data?.collections ?? [], 'No client collections for this day.')}</section>
  </main></DashboardLayout>;
}
