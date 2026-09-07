import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, ArrowRight, Banknote, BrainCircuit, ChartNoAxesCombined, CircleDollarSign, Landmark, RefreshCw, ReceiptText, ShieldAlert, ShieldCheck, Sparkles, WalletCards } from 'lucide-react';
import { Link } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { formatPHP } from '@/lib/currency';
import api from '@/services/api';
import { useAuth } from '@/hooks/useAuth';

type WalletName = 'cash' | 'gcash' | 'bpi' | 'landbank' | 'online';
type Wallet = {
  collections: number;
  cash_in: number;
  transfers_in: number;
  transfers_out: number;
  expenses: number;
  processing_fees: number;
  balance: number;
};
type DailyMetric = { date: string; billed: number; collections: number; cash_in: number; expenses: number; processing_fees: number; net_operating_movement: number };
type Allocation = { key: string; label: string; percent_of_planning_base: number; percent_of_collections: number; amount: number };
type Anomaly = { type: string; severity: 'review' | 'monitor'; message: string; amount_total?: number; customer?: { account_number?: string | null; full_name?: string | null }; payment_numbers?: string[]; invoice_numbers?: string[]; payment_count?: number; invoice_count?: number; remittance_count?: number };
type CollectorCashRow = {
  collector_id: string; collector_name: string; is_active: boolean;
  unremitted_payment_count: number; cash_on_hand: number; oldest_unremitted_date: string | null;
  awaiting_office_review: number; discrepancy_count: number; discrepancy_variance: number;
  period_cash_collected: number; period_cash_submitted: number; period_cash_liquidated: number; period_cash_verified: number;
  submission_progress_percent: number; verification_progress_percent: number;
};
type MonitoringData = {
  period: { month: string; start: string; end: string; timezone: string };
  flow: { billed: number; collections: number; cash_in: number; expenses: number; payment_processing_fees: number; net_collections_after_fees: number; net_operating_movement: number; collection_rate_percent: number | null; expense_ratio_percent: number | null };
  paymongo_settlements: { gross_amount: number; fee_amount: number; net_amount: number; confirmed_count: number; pending_count: number; overall: { gross_amount: number; fee_amount: number; net_amount: number; confirmed_count: number; tracking_started_at: string | null }; transactions: Array<{ payment_number: string | null; invoice_number: string | null; customer_name: string | null; account_number: string | null; payment_method: string | null; gross_amount: number; fee_amount: number; net_amount: number; paid_at: string | null }> };
  wallets: Record<WalletName, Wallet>;
  daily_metrics: DailyMetric[];
  allocation_plan: { collection_base: number; planning_base: number; retained_operations: number; allocations: Allocation[]; note: string };
  accounts_receivable: { open_invoice_count: number; outstanding_balance: number; overdue_balance: number; available_advance_credit: number };
  remittances: {
    pending_count: number;
    pending_declared_amount: number;
    submitted_count: number;
    submitted_declared_amount: number;
    discrepancy_count: number;
    discrepancy_declared_amount: number;
    discrepancy_variance_amount: number;
  };
  collector_cash: { summary: { collector_count: number; cash_on_hand: number; awaiting_office_review: number; discrepancy_variance: number }; collectors: CollectorCashRow[] };
  study: { headline: string; findings: string[]; action_required: string };
  anomalies: { summary: { review_count: number; duplicate_payment_count: number; duplicate_invoice_count: number }; items: Anomaly[] };
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
  const { user } = useAuth();
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
  const anomalies = data?.anomalies.items ?? [];
  const totalWalletMovement = WALLET_DISPLAY.reduce((total, wallet) => total + (wallets?.[wallet.key]?.balance ?? 0), 0);
  const refreshedAt = data?.generated_at ? new Date(data.generated_at).toLocaleString('en-PH') : null;
  const roleNames = [user?.role, ...(user?.roles ?? []).map((role) => typeof role === 'string' ? role : role.name)].filter(Boolean);
  const canReviewRemittances = roleNames.some((role) => ['super_admin', 'admin', 'cashier', 'office_admin'].includes(role as string));
  const askFinanceAi = (): void => {
    window.dispatchEvent(new CustomEvent('solarnet:open-ai', {
      detail: { prompt: `Explain the verified Financial Monitoring study for ${month}. Use the finance tool, show Result, Data source, Calculation, Findings, Risk, Recommendation, and Action required. Do not change any financial record.` },
    }));
  };

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
          <MetricCard icon={<Banknote className="h-4 w-4" />} label="PayMongo net received" value={formatPHP(data?.paymongo_settlements.net_amount)} detail={`${formatPHP(data?.paymongo_settlements.fee_amount)} actual provider fees`} />
          <MetricCard icon={<WalletCards className="h-4 w-4" />} label="Net operating movement" value={formatPHP(data?.flow.net_operating_movement)} detail="Collections + cash in − expenses" positive={(data?.flow.net_operating_movement ?? 0) >= 0} />
          <MetricCard icon={<Landmark className="h-4 w-4" />} label="Outstanding receivables" value={formatPHP(data?.accounts_receivable.outstanding_balance)} detail={`${data?.accounts_receivable.open_invoice_count ?? 0} open invoice${(data?.accounts_receivable.open_invoice_count ?? 0) === 1 ? '' : 's'}`} />
        </section>

        <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
          <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold text-foreground">PayMongo settlement deductions</h2><p className="mt-1 text-sm text-muted-foreground">Actual provider-reported values only. Customer payments stay at their full gross amount; fees reduce operational cash, not invoice credit.</p></div><div className="text-right"><p className="text-lg font-bold text-foreground">Net {formatPHP(data?.paymongo_settlements.net_amount)}</p><p className="text-xs text-muted-foreground">{data?.paymongo_settlements.confirmed_count ?? 0} confirmed · {data?.paymongo_settlements.pending_count ?? 0} awaiting provider details</p></div></div>
          <div className="mt-4 grid gap-3 sm:grid-cols-3"><AllocationCard label="Gross customer payments" value={formatPHP(data?.paymongo_settlements.gross_amount)} detail="Full amount credited to invoices" /><AllocationCard label="PayMongo deductions" value={formatPHP(data?.paymongo_settlements.fee_amount)} detail="Actual transaction fees" accent="text-red-600 dark:text-red-400" /><AllocationCard label="Net settlement" value={formatPHP(data?.paymongo_settlements.net_amount)} detail="Gross less provider fee" accent="text-emerald-600 dark:text-emerald-400" /></div>
          <p className="mt-3 rounded-xl bg-muted px-3 py-2 text-xs text-muted-foreground">Overall since actual-fee tracking began: <b className="text-foreground">{formatPHP(data?.paymongo_settlements.overall.gross_amount)}</b> gross − <b className="text-red-600 dark:text-red-400">{formatPHP(data?.paymongo_settlements.overall.fee_amount)}</b> fees = <b className="text-emerald-600 dark:text-emerald-400">{formatPHP(data?.paymongo_settlements.overall.net_amount)}</b> net across {data?.paymongo_settlements.overall.confirmed_count ?? 0} confirmed transaction(s).</p>
          {!!data?.paymongo_settlements.transactions.length && <div className="mt-4 max-h-72 overflow-auto rounded-xl border border-border"><table className="w-full min-w-[720px] text-left text-xs"><thead className="sticky top-0 bg-muted text-muted-foreground"><tr><th className="p-2">Payment / customer</th><th className="p-2">Method</th><th className="p-2 text-right">Gross</th><th className="p-2 text-right">Fee</th><th className="p-2 text-right">Net</th></tr></thead><tbody>{data.paymongo_settlements.transactions.map((row, index) => <tr key={`${row.payment_number}-${index}`} className="border-t border-border"><td className="p-2"><b className="text-foreground">{row.payment_number ?? row.invoice_number}</b><br /><span className="text-muted-foreground">{row.customer_name} · {row.account_number}</span></td><td className="p-2 uppercase text-muted-foreground">{row.payment_method ?? 'PayMongo'}</td><td className="p-2 text-right text-foreground">{formatPHP(row.gross_amount)}</td><td className="p-2 text-right text-red-600 dark:text-red-400">−{formatPHP(row.fee_amount)}</td><td className="p-2 text-right font-semibold text-emerald-600 dark:text-emerald-400">{formatPHP(row.net_amount)}</td></tr>)}</tbody></table></div>}
        </section>

        <section className="rounded-xl border border-border bg-card p-2.5 sm:p-3">
          <div className="flex flex-wrap items-start justify-between gap-2"><div><div className="flex items-center gap-1.5"><ChartNoAxesCombined className="h-3.5 w-3.5 text-primary" /><h2 className="text-sm font-semibold text-foreground">Daily metrics graph</h2></div><p className="mt-0.5 text-xs text-muted-foreground">Collections, expenses, and net movement by day. Transfers are excluded.</p></div><div className="flex flex-wrap gap-x-2.5 gap-y-1 text-[11px] text-muted-foreground"><span><i className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-emerald-500" />Collections</span><span><i className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-amber-500" />Expenses</span><span><i className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-sky-500" />Net</span></div></div>
          <FinanceMetricsGraph metrics={data?.daily_metrics ?? []} />
          <div className="mt-2 grid gap-1 text-[11px] text-muted-foreground sm:grid-cols-2"><p>Collected-to-billed: <b className="text-foreground">{data?.flow.collection_rate_percent === null || data?.flow.collection_rate_percent === undefined ? '—' : `${data.flow.collection_rate_percent.toFixed(2)}%`}</b></p><p>Expense-to-collections: <b className="text-foreground">{data?.flow.expense_ratio_percent === null || data?.flow.expense_ratio_percent === undefined ? '—' : `${data.flow.expense_ratio_percent.toFixed(2)}%`}</b></p></div>
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

        <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
          <div className="flex flex-wrap items-start justify-between gap-3"><div className="flex gap-2"><Banknote className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" /><div><h2 className="font-semibold text-foreground">Collector cash accountability</h2><p className="mt-1 max-w-3xl text-sm text-muted-foreground">Tracks every user assigned the Collector role. Expected cash on hand comes only from recorded cash payments that have no remittance yet; it does not include office collections or online payments.</p></div></div><span className="rounded-full bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300">{data?.collector_cash?.summary.collector_count ?? 0} collector{(data?.collector_cash?.summary.collector_count ?? 0) === 1 ? '' : 's'}</span></div>
          <div className="mt-4 grid gap-3 sm:grid-cols-3"><AllocationCard label="Expected cash still on hand" value={formatPHP(data?.collector_cash?.summary.cash_on_hand)} detail="Cash payments not attached to any remittance" accent={(data?.collector_cash?.summary.cash_on_hand ?? 0) > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-600 dark:text-emerald-400'} /><AllocationCard label="Submitted, awaiting office review" value={formatPHP(data?.collector_cash?.summary.awaiting_office_review)} detail="Already placed in a remittance; cashier validation is unfinished" /><AllocationCard label="Recorded discrepancy difference" value={formatPHP(data?.collector_cash?.summary.discrepancy_variance)} detail="Absolute difference between declared and received amounts" accent={(data?.collector_cash?.summary.discrepancy_variance ?? 0) > 0 ? 'text-red-600 dark:text-red-400' : 'text-foreground'} /></div>
          <div className="mt-4 grid gap-3 lg:grid-cols-2">{data?.collector_cash?.collectors.length ? data.collector_cash.collectors.map((collector) => <CollectorCashCard key={collector.collector_id} collector={collector} />) : <p className="rounded-xl border border-dashed border-border p-5 text-sm text-muted-foreground">No users with the Collector role were found.</p>}</div>
          <p className="mt-4 rounded-xl bg-muted px-3 py-2 text-xs leading-5 text-muted-foreground"><b className="text-foreground">Reading the flow:</b> Collected → submitted means the collector attached the payment to a remittance. Submitted → liquidated means office staff counted the cash. Verified means the remittance was accepted without a discrepancy. This monitor is read-only and never changes a payment or remittance.</p>
        </section>

        <section className="grid gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
          <article className="rounded-2xl border border-border bg-card p-4 sm:p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div><div className="flex items-center gap-2"><Landmark className="h-4 w-4 text-primary" /><h2 className="font-semibold text-foreground">Collectibles allocation plan</h2></div><p className="mt-1 text-sm text-muted-foreground">Planning only. It does not reserve, transfer, approve, or lend funds.</p></div><p className="rounded-lg bg-primary/10 px-3 py-2 text-xs font-semibold text-primary">80% planning base</p></div>
            <div className="mt-4 grid gap-3 sm:grid-cols-2"><AllocationCard label="Planning base" value={formatPHP(data?.allocation_plan.planning_base)} detail={`80% of ${formatPHP(data?.allocation_plan.collection_base)}`} accent="text-primary" />{data?.allocation_plan.allocations.map((allocation) => <AllocationCard key={allocation.key} label={allocation.label} value={formatPHP(allocation.amount)} detail={`${allocation.percent_of_planning_base}% of plan · ${allocation.percent_of_collections}% of collections`} />)}<AllocationCard label="Operations retained" value={formatPHP(data?.allocation_plan.retained_operations)} detail="20% outside the allocation plan" /></div>
            <p className="mt-4 rounded-xl bg-muted px-3 py-2 text-xs text-muted-foreground">{data?.allocation_plan.note ?? 'Loading allocation policy…'}</p>
          </article>

          <article className="rounded-2xl border border-violet-200 bg-violet-50/50 p-4 sm:p-5 dark:border-violet-900/60 dark:bg-violet-950/20"><div className="flex flex-wrap items-start justify-between gap-3"><div className="flex gap-2"><BrainCircuit className="mt-0.5 h-5 w-5 shrink-0 text-violet-700 dark:text-violet-300" /><div><h2 className="font-semibold text-foreground">Finance study & AI interpreter</h2><p className="mt-1 text-sm text-muted-foreground">Deterministic study first; the AI only explains verified data.</p></div></div><button type="button" onClick={askFinanceAi} className="inline-flex items-center gap-2 rounded-lg bg-violet-700 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-800 dark:bg-violet-500 dark:text-slate-950 dark:hover:bg-violet-400"><Sparkles className="h-4 w-4" />Ask Finance AI</button></div>
            <p className="mt-4 font-semibold text-foreground">{data?.study.headline ?? 'Loading finance study…'}</p><ul className="mt-3 list-disc space-y-2 pl-5 text-sm text-muted-foreground">{data?.study.findings.map((item) => <li key={item}>{item}</li>)}</ul><p className="mt-4 rounded-xl border border-violet-200 bg-background/80 px-3 py-2 text-xs text-muted-foreground dark:border-violet-900/60"><b className="text-foreground">Action required:</b> {data?.study.action_required ?? 'Loading…'}</p>
          </article>
        </section>

        <section className="rounded-2xl border border-border bg-card p-4 sm:p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div className="flex gap-2"><ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" /><div><h2 className="font-semibold text-foreground">Detection anomalies</h2><p className="mt-1 text-sm text-muted-foreground">Read-only candidates from deterministic rules. Nothing is corrected automatically.</p></div></div><div className="flex gap-2 text-xs"><span className="rounded-full bg-amber-100 px-2 py-1 font-semibold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">{data?.anomalies.summary.review_count ?? 0} to review</span><span className="rounded-full bg-muted px-2 py-1 font-semibold text-muted-foreground">Payments {data?.anomalies.summary.duplicate_payment_count ?? 0} · Invoices {data?.anomalies.summary.duplicate_invoice_count ?? 0}</span></div></div>
          <div className="mt-4 grid gap-3 md:grid-cols-2">{anomalies.length ? anomalies.map((item, index) => <AnomalyCard key={`${item.type}-${index}`} item={item} />) : <p className="rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">No review candidates were detected by the current duplicate-payment, duplicate-invoice, remittance, and overdue-receivable checks.</p>}</div>
        </section>

        <section className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(18rem,1fr)]">
          <article className="rounded-2xl border border-border bg-card p-4 sm:p-5">
            <div className="flex flex-wrap items-center justify-between gap-2"><div><h2 className="font-semibold text-foreground">Channel position</h2><p className="mt-1 text-sm text-muted-foreground">Separate operational movement for each money channel.</p></div><p className="text-sm font-semibold text-foreground">Total {formatPHP(totalWalletMovement)}</p></div>
            <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {WALLET_DISPLAY.map(({ key, label, accent }) => {
                const wallet = wallets?.[key] ?? { collections: 0, processing_fees: 0, cash_in: 0, transfers_in: 0, transfers_out: 0, expenses: 0, balance: 0 };
                return <article key={key} className="rounded-xl border border-border bg-background p-3"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p><p className={`mt-1 text-xl font-bold ${accent}`}>{formatPHP(wallet.balance)}</p><dl className="mt-3 space-y-1 text-xs text-muted-foreground"><div className="flex justify-between gap-3"><dt>Collections</dt><dd>{formatPHP(wallet.collections)}</dd></div><div className="flex justify-between gap-3"><dt>Provider fees</dt><dd>−{formatPHP(wallet.processing_fees)}</dd></div><div className="flex justify-between gap-3"><dt>In / transfer in</dt><dd>{formatPHP(wallet.cash_in + wallet.transfers_in)}</dd></div><div className="flex justify-between gap-3"><dt>Expenses / transfer out</dt><dd>{formatPHP(wallet.expenses + wallet.transfers_out)}</dd></div></dl></article>;
              })}
            </div>
          </article>

          <article className="rounded-2xl border border-border bg-card p-4 sm:p-5">
            <h2 className="font-semibold text-foreground">Controls requiring staff review</h2>
            <p className="mt-1 text-sm text-muted-foreground">These figures flag unfinished verification work. They do not automatically mean money is missing.</p>
            <div className="mt-4 space-y-3">
              <RemittanceReviewCard remittances={data?.remittances} canReview={canReviewRemittances} />
              <ReviewRow label="Overdue receivables" value={formatPHP(data?.accounts_receivable.overdue_balance)} detail="Remaining customer debt on open invoices whose due date has passed. This is money still collectible, not cash already received." warning={(data?.accounts_receivable.overdue_balance ?? 0) > 0} />
              <ReviewRow label="Available advance credit" value={formatPHP(data?.accounts_receivable.available_advance_credit)} detail="Customer money already recorded as unused credit and reserved for future invoices. It is not another unpaid balance." />
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

function RemittanceReviewCard({ remittances, canReview }: { remittances?: MonitoringData['remittances']; canReview: boolean }): React.JSX.Element {
  const pending = remittances?.pending_count ?? 0;
  const submitted = remittances?.submitted_count ?? 0;
  const discrepancies = remittances?.discrepancy_count ?? 0;

  return <div className={`rounded-xl border p-3.5 ${pending > 0 ? 'border-amber-300 bg-amber-50/70 dark:border-amber-900/70 dark:bg-amber-950/20' : 'border-border bg-background'}`}>
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div><p className="text-sm font-semibold text-foreground">Collector remittances awaiting review</p><p className="mt-1 text-xs leading-5 text-muted-foreground">Collector remittance records still open because they await office liquidation or contain a verified mismatch.</p></div>
      <div className="text-right"><p className={`text-lg font-bold ${pending > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-foreground'}`}>{formatPHP(remittances?.pending_declared_amount)}</p><p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Combined declared amount</p></div>
    </div>
    <div className="mt-3 grid gap-2 sm:grid-cols-2">
      <div className="rounded-lg border border-border/70 bg-background/80 p-2.5"><p className="text-xs font-semibold text-foreground">{submitted} submitted</p><p className="mt-0.5 text-sm font-bold text-foreground">{formatPHP(remittances?.submitted_declared_amount)}</p><p className="mt-1 text-[11px] leading-4 text-muted-foreground">Waiting for an administrator or cashier to count and liquidate the collector cash.</p></div>
      <div className="rounded-lg border border-border/70 bg-background/80 p-2.5"><p className="text-xs font-semibold text-foreground">{discrepancies} with discrepancy</p><p className="mt-0.5 text-sm font-bold text-foreground">{formatPHP(remittances?.discrepancy_declared_amount)}</p><p className="mt-1 text-[11px] leading-4 text-muted-foreground">Already checked, but the received amount differed. Recorded difference: <b className="text-foreground">{formatPHP(remittances?.discrepancy_variance_amount)}</b>.</p></div>
    </div>
    <div className="mt-3 rounded-lg bg-background/70 p-2.5 text-[11px] leading-5 text-muted-foreground"><b className="text-foreground">What to do:</b> Open Remittances, compare each declaration with its linked payments and actual cash, liquidate and validate matching submissions, then investigate every recorded discrepancy according to your finance policy. The combined declared amount is not automatically a cash shortage.</div>
    <div className="mt-3 flex items-center justify-between gap-3"><p className="text-xs text-muted-foreground">{pending} record{pending === 1 ? '' : 's'} still require attention.</p>{canReview && <Link to="/remittances" className="inline-flex items-center gap-1 rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-700">Review remittances <ArrowRight className="h-3.5 w-3.5" /></Link>}</div>
  </div>;
}

function CollectorCashCard({ collector }: { collector: CollectorCashRow }): React.JSX.Element {
  const hasCash = collector.cash_on_hand > 0;
  const progress = (value: number): string => collector.period_cash_collected > 0 ? `${value.toFixed(1)}%` : 'No cash collections';
  return <article className={`rounded-xl border p-3.5 ${hasCash ? 'border-amber-300/80 bg-amber-50/50 dark:border-amber-900/70 dark:bg-amber-950/15' : 'border-border bg-background'}`}>
    <div className="flex items-start justify-between gap-3"><div><div className="flex flex-wrap items-center gap-2"><p className="font-semibold text-foreground">{collector.collector_name}</p><span className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${collector.is_active ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-muted text-muted-foreground'}`}>{collector.is_active ? 'Active' : 'Inactive'}</span></div><p className="mt-1 text-xs text-muted-foreground">{collector.unremitted_payment_count} unremitted cash payment{collector.unremitted_payment_count === 1 ? '' : 's'}{collector.oldest_unremitted_date ? ` · oldest ${collector.oldest_unremitted_date}` : ''}</p></div><div className="text-right"><p className={`text-lg font-bold ${hasCash ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-600 dark:text-emerald-400'}`}>{formatPHP(collector.cash_on_hand)}</p><p className="text-[10px] uppercase tracking-wide text-muted-foreground">Expected on hand</p></div></div>
    <dl className="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4"><div className="rounded-lg bg-background/80 p-2"><dt className="text-muted-foreground">Collected</dt><dd className="mt-1 font-bold text-foreground">{formatPHP(collector.period_cash_collected)}</dd></div><div className="rounded-lg bg-background/80 p-2"><dt className="text-muted-foreground">Submitted</dt><dd className="mt-1 font-bold text-foreground">{formatPHP(collector.period_cash_submitted)}</dd></div><div className="rounded-lg bg-background/80 p-2"><dt className="text-muted-foreground">Liquidated</dt><dd className="mt-1 font-bold text-foreground">{formatPHP(collector.period_cash_liquidated)}</dd></div><div className="rounded-lg bg-background/80 p-2"><dt className="text-muted-foreground">Verified</dt><dd className="mt-1 font-bold text-foreground">{formatPHP(collector.period_cash_verified)}</dd></div></dl>
    <div className="mt-3 space-y-2"><div><div className="mb-1 flex justify-between text-[11px]"><span className="text-muted-foreground">Submission progress</span><b className="text-foreground">{progress(collector.submission_progress_percent)}</b></div><div className="h-2 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-sky-500 transition-all" style={{ width: `${collector.period_cash_collected > 0 ? collector.submission_progress_percent : 0}%` }} /></div></div><div><div className="mb-1 flex justify-between text-[11px]"><span className="text-muted-foreground">Final verification progress</span><b className="text-foreground">{progress(collector.verification_progress_percent)}</b></div><div className="h-2 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-emerald-500 transition-all" style={{ width: `${collector.period_cash_collected > 0 ? collector.verification_progress_percent : 0}%` }} /></div></div></div>
    {(collector.awaiting_office_review > 0 || collector.discrepancy_count > 0) && <p className="mt-3 rounded-lg border border-border/60 bg-background/70 p-2 text-[11px] leading-5 text-muted-foreground">Awaiting office review: <b className="text-foreground">{formatPHP(collector.awaiting_office_review)}</b>{collector.discrepancy_count > 0 ? <> · {collector.discrepancy_count} discrepancy record{collector.discrepancy_count === 1 ? '' : 's'} totaling <b className="text-red-600 dark:text-red-400">{formatPHP(collector.discrepancy_variance)}</b> difference</> : null}</p>}
  </article>;
}

function AllocationCard({ label, value, detail, accent = 'text-foreground' }: { label: string; value: string; detail: string; accent?: string }): React.JSX.Element {
  return <div className="rounded-xl border border-border bg-background p-3"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p><p className={`mt-1 text-lg font-bold ${accent}`}>{value}</p><p className="mt-1 text-xs text-muted-foreground">{detail}</p></div>;
}

function AnomalyCard({ item }: { item: Anomaly }): React.JSX.Element {
  const identity = [item.customer?.full_name, item.customer?.account_number].filter(Boolean).join(' · ');
  const records = item.payment_numbers?.length ? item.payment_numbers.join(', ') : item.invoice_numbers?.join(', ');
  return <article className="rounded-xl border border-border bg-background p-3"><div className="flex flex-wrap items-start justify-between gap-2"><p className="text-sm font-semibold capitalize text-foreground">{item.type.replaceAll('_', ' ')}</p><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${item.severity === 'review' ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300'}`}>{item.severity}</span></div><p className="mt-2 text-sm text-muted-foreground">{item.message}</p>{identity && <p className="mt-2 text-xs font-medium text-foreground">{identity}</p>}{records && <p className="mt-1 break-words text-xs text-muted-foreground">{records}</p>}{item.amount_total !== undefined && <p className="mt-3 text-sm font-bold text-foreground">{formatPHP(item.amount_total)}</p>}</article>;
}

function FinanceMetricsGraph({ metrics }: { metrics: DailyMetric[] }): React.JSX.Element {
  if (!metrics.length) return <p className="mt-5 rounded-xl border border-dashed border-border p-5 text-sm text-muted-foreground">No days are available to graph for this period.</p>;
  const width = 760; const height = 142; const left = 40; const right = 12; const top = 10; const bottom = 24;
  const values = metrics.flatMap((metric) => [metric.collections, metric.expenses, metric.net_operating_movement]);
  const maximum = Math.max(1, ...values, 0); const minimum = Math.min(0, ...values); const range = Math.max(1, maximum - minimum);
  const x = (index: number): number => left + (index / Math.max(1, metrics.length - 1)) * (width - left - right);
  const y = (value: number): number => top + ((maximum - value) / range) * (height - top - bottom);
  const points = (key: keyof Pick<DailyMetric, 'collections' | 'expenses' | 'net_operating_movement'>): string => metrics.map((metric, index) => `${x(index)},${y(metric[key])}`).join(' ');
  const zeroLine = y(0); const labels = metrics.filter((_, index) => index === 0 || index === metrics.length - 1 || index % Math.max(1, Math.ceil(metrics.length / 6)) === 0);
  return <div className="mt-1.5 overflow-x-auto"><svg viewBox={`0 0 ${width} ${height}`} className="min-w-[27rem] w-full" role="img" aria-label="Daily collections, expenses, and net operating movement graph"><line x1={left} x2={width - right} y1={zeroLine} y2={zeroLine} className="stroke-border" strokeDasharray="3 3" /><text x={3} y={top + 4} className="fill-muted-foreground text-[9px]">{formatPHP(maximum)}</text><text x={3} y={height - bottom + 4} className="fill-muted-foreground text-[9px]">{formatPHP(minimum)}</text><polyline fill="none" stroke="currentColor" className="text-emerald-500" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" points={points('collections')} /><polyline fill="none" stroke="currentColor" className="text-amber-500" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" points={points('expenses')} /><polyline fill="none" stroke="currentColor" className="text-sky-500" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" points={points('net_operating_movement')} />{labels.map((metric) => { const index = metrics.indexOf(metric); return <text key={metric.date} x={x(index)} y={height - 7} textAnchor="middle" className="fill-muted-foreground text-[8px]">{metric.date.slice(8)}</text>; })}</svg></div>;
}
