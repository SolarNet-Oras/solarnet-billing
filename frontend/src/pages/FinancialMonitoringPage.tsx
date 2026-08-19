import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, ArrowRight, Banknote, CircleDollarSign, Landmark, RefreshCw, ReceiptText, ShieldCheck, WalletCards } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { formatPHP } from '@/lib/currency';
import api from '@/services/api';

type WalletName = 'cash' | 'gcash' | 'bpi' | 'landbank' | 'online';
type Wallet = {
  collections: number;
  cash_in: number;
  transfers_in: number;
  transfers_out: number;
  expenses: number;
  balance: number;
};
type MonitoringData = {
  period: { month: string; start: string; end: string; timezone: string };
  flow: { billed: number; collections: number; cash_in: number; expenses: number; net_operating_movement: number };
  wallets: Record<WalletName, Wallet>;
  accounts_receivable: { open_invoice_count: number; outstanding_balance: number; overdue_balance: number; available_advance_credit: number };
  remittances: { pending_count: number; pending_declared_amount: number };
  data_sources: string[];
  limitations: string[];
  generated_at: string;
};

const WALLET_DISPLAY: Array<{ key: WalletName; label: string; accent: string }> = [
  { key: 'cash', label: 'Cash', accent: 'text-emerald-600 dark:text-emerald-400' },
  { key: 'gcash', label: 'GCash', accent: 'text-violet-600 dark:text-violet-400' },
  { key: 'bpi', label: 'BPI', accent: 'text-blue-600 dark:text-blue-400' },
  { key: 'landbank', label: 'Landbank', accent: 'text-cyan-600 dark:text-cyan-400' },
  { key: 'online', label: 'Online', accent: 'text-amber-600 dark:text-amber-400' },
];

const currentMonth = (): string => new Date().toISOString().slice(0, 7);

export default function FinancialMonitoringPage(): React.JSX.Element {
  const [month, setMonth] = useState(currentMonth());
  const [data, setData] = useState<MonitoringData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const response = await api.get('/financial-monitoring', { params: { month } });
      setData(response.data.data);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not load financial monitoring.');
    } finally {
      setLoading(false);
    }
  }, [month]);

  useEffect(() => { void load(); }, [load]);

  const wallets = data?.wallets;
  const totalWalletMovement = WALLET_DISPLAY.reduce((total, wallet) => total + (wallets?.[wallet.key]?.balance ?? 0), 0);
  const refreshedAt = data?.generated_at ? new Date(data.generated_at).toLocaleString('en-PH') : null;

  return (
    <DashboardLayout>
      <main className="mx-auto max-w-7xl space-y-5">
        <header className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <div className="flex items-center gap-2 text-foreground"><CircleDollarSign className="h-5 w-5 text-primary" /><h1 className="text-xl font-bold sm:text-2xl">Financial Monitoring</h1></div>
            <p className="mt-1 max-w-3xl text-sm text-muted-foreground">A concise, read-only operational flow from actual invoices, payments, remittances, advance credits, and Daily Operations.</p>
          </div>
          <div className="flex flex-wrap items-end gap-2">
            <label className="text-xs font-semibold text-muted-foreground">Month<input aria-label="Financial monitoring month" type="month" value={month} onChange={(event) => setMonth(event.target.value)} className="mt-1 block rounded-lg border border-input bg-background px-3 py-2 text-sm font-medium text-foreground [color-scheme:light] dark:[color-scheme:dark]" /></label>
            <button type="button" onClick={() => void load()} disabled={loading} className="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-3 text-sm font-semibold text-primary-foreground disabled:opacity-60"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh</button>
          </div>
        </header>

        {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300">{error}</p>}

        <section aria-live="polite" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <MetricCard icon={<ReceiptText className="h-4 w-4" />} label="Collections received" value={formatPHP(data?.flow.collections)} detail="Recognized company collections" />
          <MetricCard icon={<Banknote className="h-4 w-4" />} label="Operating expenses" value={formatPHP(data?.flow.expenses)} detail="Approved daily-operation expenses" />
          <MetricCard icon={<WalletCards className="h-4 w-4" />} label="Net operating movement" value={formatPHP(data?.flow.net_operating_movement)} detail="Collections + cash in − expenses" positive={(data?.flow.net_operating_movement ?? 0) >= 0} />
          <MetricCard icon={<Landmark className="h-4 w-4" />} label="Outstanding receivables" value={formatPHP(data?.accounts_receivable.outstanding_balance)} detail={`${data?.accounts_receivable.open_invoice_count ?? 0} open invoice${(data?.accounts_receivable.open_invoice_count ?? 0) === 1 ? '' : 's'}`} />
        </section>

        <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
          <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold text-foreground">Financial flow</h2><p className="mt-1 text-sm text-muted-foreground">Invoices are billed amounts. Only recorded payments are shown as collections.</p></div>{refreshedAt && <p className="text-xs text-muted-foreground">Updated {refreshedAt}</p>}</div>
          <div className="mt-4 grid gap-3 md:grid-cols-[1fr_auto_1fr_auto_1fr_auto_1fr] md:items-stretch">
            <FlowStep label="Invoiced" value={formatPHP(data?.flow.billed)} detail="Issued during selected month" />
            <FlowArrow />
            <FlowStep label="Collected" value={formatPHP(data?.flow.collections)} detail="Paid and recognized" accent="text-emerald-600 dark:text-emerald-400" />
            <FlowArrow />
            <FlowStep label="Cash in / expenses" value={`${formatPHP(data?.flow.cash_in)} / ${formatPHP(data?.flow.expenses)}`} detail="Non-billing operational entries" />
            <FlowArrow />
            <FlowStep label="Net movement" value={formatPHP(data?.flow.net_operating_movement)} detail="Transfers excluded" accent={(data?.flow.net_operating_movement ?? 0) < 0 ? 'text-red-600 dark:text-red-400' : 'text-primary'} />
          </div>
        </section>

        <section className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(18rem,1fr)]">
          <article className="rounded-2xl border border-border bg-card p-4 sm:p-5">
            <div className="flex flex-wrap items-center justify-between gap-2"><div><h2 className="font-semibold text-foreground">Channel position</h2><p className="mt-1 text-sm text-muted-foreground">Separate operational movement for each money channel.</p></div><p className="text-sm font-semibold text-foreground">Total {formatPHP(totalWalletMovement)}</p></div>
            <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {WALLET_DISPLAY.map(({ key, label, accent }) => {
                const wallet = wallets?.[key] ?? { collections: 0, cash_in: 0, transfers_in: 0, transfers_out: 0, expenses: 0, balance: 0 };
                return <article key={key} className="rounded-xl border border-border bg-background p-3"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p><p className={`mt-1 text-xl font-bold ${accent}`}>{formatPHP(wallet.balance)}</p><dl className="mt-3 space-y-1 text-xs text-muted-foreground"><div className="flex justify-between gap-3"><dt>Collections</dt><dd>{formatPHP(wallet.collections)}</dd></div><div className="flex justify-between gap-3"><dt>In / transfer in</dt><dd>{formatPHP(wallet.cash_in + wallet.transfers_in)}</dd></div><div className="flex justify-between gap-3"><dt>Expenses / transfer out</dt><dd>{formatPHP(wallet.expenses + wallet.transfers_out)}</dd></div></dl></article>;
              })}
            </div>
          </article>

          <article className="rounded-2xl border border-border bg-card p-4 sm:p-5">
            <h2 className="font-semibold text-foreground">Controls to review</h2>
            <div className="mt-4 space-y-3">
              <ReviewRow label="Collector remittances awaiting review" value={formatPHP(data?.remittances.pending_declared_amount)} detail={`${data?.remittances.pending_count ?? 0} pending submitted/discrepancy remittance(s)`} warning={(data?.remittances.pending_count ?? 0) > 0} />
              <ReviewRow label="Overdue receivables" value={formatPHP(data?.accounts_receivable.overdue_balance)} detail="Open invoices past due date" warning={(data?.accounts_receivable.overdue_balance ?? 0) > 0} />
              <ReviewRow label="Available advance credit" value={formatPHP(data?.accounts_receivable.available_advance_credit)} detail="Customer credit not yet applied to an invoice" />
            </div>
          </article>
        </section>

        <section className="grid gap-4 lg:grid-cols-2">
          <article className="rounded-2xl border border-border bg-card p-4 sm:p-5"><div className="flex gap-2"><ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /><div><h2 className="font-semibold text-foreground">Verified data sources</h2><ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">{data?.data_sources.map((item) => <li key={item}>{item}</li>) ?? <li>Loading sources…</li>}</ul></div></div></article>
          <article className="rounded-2xl border border-amber-200 bg-amber-50/70 p-4 sm:p-5 dark:border-amber-900/60 dark:bg-amber-950/20"><div className="flex gap-2"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-700 dark:text-amber-400" /><div><h2 className="font-semibold text-foreground">Interpretation limits</h2><ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted-foreground">{data?.limitations.map((item) => <li key={item}>{item}</li>) ?? <li>Loading limits…</li>}</ul></div></div></article>
        </section>
      </main>
    </DashboardLayout>
  );
}

function MetricCard({ icon, label, value, detail, positive }: { icon: React.ReactNode; label: string; value: string; detail: string; positive?: boolean }): React.JSX.Element {
  const valueClass = positive === undefined ? 'text-foreground' : positive ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400';
  return <article className="rounded-2xl border border-border bg-card p-4"><div className="flex items-center gap-2 text-muted-foreground">{icon}<p className="text-xs font-semibold uppercase tracking-wide">{label}</p></div><p className={`mt-2 text-2xl font-bold ${valueClass}`}>{value}</p><p className="mt-1 text-xs text-muted-foreground">{detail}</p></article>;
}

function FlowStep({ label, value, detail, accent = 'text-foreground' }: { label: string; value: string; detail: string; accent?: string }): React.JSX.Element {
  return <article className="rounded-xl border border-border bg-background p-3"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p><p className={`mt-1 text-lg font-bold ${accent}`}>{value}</p><p className="mt-1 text-xs text-muted-foreground">{detail}</p></article>;
}

function FlowArrow(): React.JSX.Element {
  return <div className="flex items-center justify-center text-muted-foreground"><ArrowRight className="h-4 w-4 rotate-90 md:rotate-0" /></div>;
}

function ReviewRow({ label, value, detail, warning = false }: { label: string; value: string; detail: string; warning?: boolean }): React.JSX.Element {
  return <div className="rounded-xl border border-border bg-background p-3"><div className="flex items-start justify-between gap-3"><div><p className="text-sm font-medium text-foreground">{label}</p><p className="mt-1 text-xs text-muted-foreground">{detail}</p></div><p className={`shrink-0 text-sm font-bold ${warning ? 'text-amber-700 dark:text-amber-400' : 'text-foreground'}`}>{value}</p></div></div>;
}
