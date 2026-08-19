# SolarNet Finance AI Architecture

## Purpose and safety boundary

SolarNet Finance AI is an analyst and controlled workflow assistant.  It is
**not** a ledger, a source of truth, or a bypass around Laravel authorization.
Every financial number must be calculated by deterministic application queries
or services.  The language model may explain verified results and prepare a
proposal, but it must never create, edit, allocate, reverse, delete, or approve
a financial record directly.

This document records the implementation baseline before Finance AI work.  It
is based on the current Laravel API, Eloquent models/services, React routes,
role middleware, existing AI implementation, and current unit-test structure.
Production records, settings, credentials, and billing rules were not changed
while preparing this report.

## Current architecture

### Application boundaries

| Area | Current implementation | Authority |
| --- | --- | --- |
| Billing | `InvoiceService` creates migration, manual, and recurring invoices; recurring cycles are keyed by `recurring_cycle_date`. | Invoice, invoice item, and customer records |
| Collections | `Payment` records cash, GCash, bank, online, collector, and advance-payment activity. | Payment records and InvoiceService allocations |
| Credit | `CustomerCredit` holds unused or cycle-reserved advance credit. | Credit allocation service |
| Remittance | `Remittance` records collector submissions, liquidation/counting, and receipt. | Remittance and linked payment records |
| Daily operations | `FinancialEntryController` records expenses, cash-in, and wallet transfers from approved dropdown definitions. | Financial entry and transaction-definition records |
| Account/service state | `CustomerAccountReconciliationService` calculates financial status. `BillingSuspensionService` uses it to safely restore or restrict service. | Invoice, payment, credit, customer and RouterOS result |
| Dashboard/reporting | `DashboardController`, `ReportController`, and Daily Operations expose summaries. | Read-only aggregate queries |
| AI | `AiService`, tool registry, conversations, messages, pending actions, and `AiAuditLog`. | Controlled tools plus normal role/permission checks |

### Identified entities

- `customers`: account, plan, installation date, service status, and related billing identity.
- `invoices` and `invoice_items`: billed service, issue/due date, balances, paid amount, status, and generation source.
- `payments`: recorded collections by channel, invoice/customer association, collector/remittance metadata, cash-count evidence, and payment confirmation email marker.
- `customer_credits`: remaining advance-payment value and reserved billing cycles.
- `remittances`: collector declaration, cash count, liquidator/receiver, timestamps, and status.
- `financial_entries` and `transaction_definitions`: non-invoice daily operations such as expenses and verified internal transfers.
- `customer_account_reconciliations`: immutable operational reconciliation/audit information tying account financial state to service eligibility.
- `ai_conversations`, `ai_messages`, `ai_pending_actions`, and `ai_audit_logs`: AI conversation and tool audit trail.

## Existing financial flows

### Monthly billing

1. `InvoiceService::generateRecurringInvoices()` selects active customers using
   their installation-date anniversary and the configured invoice lead time.
2. Company-owned plans are excluded from recurring invoice creation and
   suspension automation.
3. An invoice is created for the cycle, deterministic totals are calculated,
   and available credits are applied.  The recurring cycle key prevents a
   duplicate cycle invoice.
4. The existing notification policy distinguishes recurring invoices from
   manual, collector, and migration invoices.

### Payment and service restoration

1. A payment is recorded through an authorized payment workflow, optionally
   linked to an invoice, collector, remittance, cash count, or an advance
   credit.
2. The existing reconciliation service calculates total invoices, confirmed
   payments, allocated payments, open balance, and available credits.
3. `BillingSuspensionService` determines restoration eligibility from those
   deterministic results; a paid record is not assumed to be active until the
   RouterOS restoration outcome is recorded.

### Daily operations and channel separation

`FinancialEntryController` separately models Cash, GCash, BPI, and Landbank.
For a selected day or month it derives each wallet as:

```text
collections + cash in + transfers in - transfers out - expenses = period balance
```

Collector cash is deliberately excluded from company cash until the linked
remittance has been liquidated.  This is an important control that Finance AI
must preserve.

## Current controls and gaps

### Controls already present

- Invoice recurring-cycle idempotency and payment/credit allocation logic.
- Cash count/signature and remittance-liquidation workflows.
- Role and permission middleware on normal API endpoints.
- AI conversations are user-owned; tool calls are stored in `AiAuditLog` with
  arguments, result, status, error, and latency.
- Current AI tools use per-tool authorization rather than unrestricted SQL.

### Gaps Finance AI must not hide

- Daily Operations currently exposes a selected-period operational balance;
  it is not a formal general ledger opening/closing balance or bank statement
  reconciliation.
- No deterministic finance-read tool currently exists in `AiToolRegistry`.
- No approved accounting-adjustment workflow, journal model, payment reversal,
  refund model, or finalized month-end close has been identified.  AI must
  report these as unavailable rather than invent them.
- Existing `AiAuditLog` is useful for tool traceability but needs a dedicated,
  immutable finance-action audit schema before write proposals can be enabled.

## Proposed Finance AI design

```text
User question
  -> authenticated Finance AI route
  -> role-scoped, read-only FinanceQueryService
  -> deterministic calculation/result DTO
  -> Finance AI tool response and explanation
  -> AiAuditLog + finance data-source metadata

Sensitive correction request
  -> deterministic validation + proposal only
  -> dedicated finance approval record
  -> authorized human approval
  -> controlled Laravel service + DB transaction
  -> immutable before/after finance audit record
```

The AI never receives database credentials or raw query access.  Tools accept
validated, bounded filters and return minimum necessary fields.  Customer-level
tools must look up a customer by existing account/ID and enforce the caller's
normal permission scope before returning data.

## Incremental delivery plan

### Phase 0 — implemented in this change

- Add a role-scoped **Financial Monitoring** navigation entry and page for
  Super Administrator, Administrator, and Cashier.
- Add a read-only endpoint that derives a concise flow from existing invoices,
  payments, remittances, credits, and daily-operation entries.
- Add one role-scoped, read-only `get_financial_monitoring` AI tool backed by
  that same deterministic service. It may explain the resulting figures but
  cannot propose, approve, or apply a financial change.
- Make the page label the figures as operational monitoring and state the
  exact data sources.  It creates no AI result and writes no financial data.

### Phase 1 — FinanceQueryService and read-only AI tools

Add deterministic, tested tools such as:

- `get_daily_collection(period, channel)`
- `get_monthly_collection(period, channel)`
- `get_cash_position(period)`
- `get_accounts_receivable_aging(as_of_date)`
- `get_outstanding_invoices(filters)`
- `get_customer_balance(customer)`
- `get_customer_payment_history(customer, date_range)`
- `get_revenue_comparison(current_period, prior_period)`
- `get_expenses(period, category)`
- `get_profit_loss(period)` only where revenue/cost/expense sources are
  explicitly available.

Each result must include a data-source list, deterministic formula, time zone,
period, and a `data_unavailable` state where applicable.

### Phase 2 — deterministic review rules

Create an auditable `FinanceAuditService` for read-only findings:

- duplicate payment candidates (same customer/channel/reference/amount within
  a bounded date window, never automatically deleted);
- duplicate invoice candidates (same customer and recurring cycle/period);
- payments not allocated to an invoice or credit;
- invoices whose aggregate linked payment allocation does not reconcile;
- customer service/billing reconciliation exceptions;
- collector submitted vs liquidated/remitted variance.

A finding must identify the exact customer, account, invoice/payment IDs,
actual and expected values, evidence, confidence/rule, and whether human
review is required.  It must not change the records it inspects.

### Phase 3 — approved correction workflow

Only after dedicated migrations, policies, and test coverage are approved:

1. The AI creates a proposal with a deterministic validation result.
2. A finance-authorized human sees a complete before/after review.
3. Super Administrator approval is required for destructive/reversal actions.
4. A narrowly scoped service applies the action inside a database transaction.
5. A finance audit row records user, role, proposal, approval, record IDs,
   before/after values, calculation, reason, and outcome.

No Finance AI tool will directly call an Eloquent `create`, `update`,
`delete`, payment allocator, or suspension-service mutation in Phases 0–2.

## Role model

| Role | Monitoring | Finance AI read tools | Propose correction | Approve/apply correction |
| --- | --- | --- | --- | --- |
| Super Administrator | All finance monitoring | All authorized finance reads | Yes | Yes, after policy checks |
| Administrator | All finance monitoring | Operational/AR/collection reads | Proposed only | No by default |
| Cashier | Channel and remittance monitoring | Collection/cash/remittance reads | No | No |
| Office Admin / Accounting | No new access in Phase 0 | Explicitly add later through policy | No | No |
| Collector | Existing scoped collections only | Own collection scope only if added | No | No |
| Technician / NOC / Viewer / Customer | No financial monitoring | No Finance AI tools | No | No |

The server endpoint and the React route must both enforce this.  Sidebar
visibility is only a convenience and never the authorization control.

## Finance AI response contract

For financial questions, the assistant must respond in this order:

1. **Result** — verified amount or explicit inability to verify.
2. **Data source** — relevant invoice/payment/remittance/entry records and
   date range, without exposing confidential secrets.
3. **Calculation** — deterministic formula and values used.
4. **Findings** — material observed facts.
5. **Risk** — uncertainty, missing data, unreconciled cash, or policy limits.
6. **Recommendation** — a non-destructive next step.
7. **Action required** — whether human review/approval is required.

Forecasts must be labelled **Projected** or **Estimated**, name the historical
window/method, and never be shown as actual revenue or cash.

## Test strategy

Before each later phase, add tests matching the existing PHPUnit unit-test
style:

- unit tests for each deterministic calculation and role rule;
- feature tests for endpoint authentication/authorization and minimum data
  scope;
- regression tests for full/partial/advance payment, recurring invoice,
  company-owned plan exclusion, suspension and restoration;
- anomaly rule tests for duplicate invoice/payment candidates and non-mutating
  review behavior;
- cash/channel tests for collector cash before/after liquidation, GCash, BPI,
  Landbank, expenses, and transfers;
- approval tests proving an AI prompt alone cannot mutate a finance record.

## Migration and rollback strategy

Phase 0 uses no migration and has no writes.  Later finance audit/proposal
tables must be additive, foreign-keyed where safe, and introduced with an
explicit `--force` migration plus a tested rollback path.  Existing invoice,
payment, credit, remittance, and financial-entry columns must not be renamed
or repurposed.  If a migration or deployment fails, disable the new endpoint
or route and roll back only the additive application release; never delete
production financial data to recover.
