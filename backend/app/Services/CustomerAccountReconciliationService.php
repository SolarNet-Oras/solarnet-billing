<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAccountReconciliation;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;

/**
 * The one read-model used when billing determines whether service may change.
 * Invoice balances remain the accounts-receivable source of truth; payments and
 * credits are reported for audit, not used to invent a second customer balance.
 */
class CustomerAccountReconciliationService
{
    public function snapshot(Customer $customer): array
    {
        $customer->loadMissing('servicePlan');

        $invoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'total', 'paid_amount', 'balance', 'status', 'due_date']);
        $openInvoices = $invoices->filter(fn (Invoice $invoice) => (float) $invoice->balance > 0
            && in_array($invoice->status, ['sent', 'partial', 'overdue'], true));
        $payments = Payment::query()->where('customer_id', $customer->id)->get(['id', 'invoice_id', 'amount']);
        $credits = CustomerCredit::query()->where('customer_id', $customer->id)->get(['remaining_amount']);

        $outstanding = round((float) $openInvoices->sum('balance'), 2);
        $allocated = round((float) PaymentAllocation::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->sum('amount'), 2);
        $confirmed = round((float) $payments->sum('amount'), 2);
        $availableCredit = round((float) $credits->sum('remaining_amount'), 2);
        $hasOverdue = $openInvoices->contains(fn (Invoice $invoice) => $invoice->status === 'overdue'
            || $invoice->due_date?->lte(now(config('app.timezone', 'Asia/Manila'))->startOfDay()));
        $hasPartialOpenInvoice = $openInvoices->contains(fn (Invoice $invoice) => (float) $invoice->paid_amount > 0);

        $financialStatus = $outstanding <= 0
            ? ($availableCredit > 0 ? 'credit' : 'paid')
            : ($hasPartialOpenInvoice ? 'partial' : ($hasOverdue ? 'overdue' : 'unpaid'));

        return [
            'total_invoiced' => round((float) $invoices->sum('total'), 2),
            'confirmed_payment_total' => $confirmed,
            'allocated_payment_total' => $allocated,
            'recorded_invoice_payment_total' => $allocated,
            'available_credit_total' => $availableCredit,
            'outstanding_balance' => $outstanding,
            'invoice_count' => $invoices->count(),
            'open_invoice_count' => $openInvoices->count(),
            'financial_status' => $financialStatus,
            'financially_current' => $outstanding <= 0,
            'has_overdue_invoice' => $hasOverdue,
            'open_invoice_ids' => $openInvoices->pluck('id')->values()->all(),
        ];
    }

    /**
     * Automated billing restrictions may be removed when no invoice remains
     * suspension-eligible. An administrator-forced suspension may also be
     * removed by payment reconciliation, but only after every open invoice is
     * fully settled. Unknown/technical holds remain protected.
     */
    public function restorationEligibility(Customer $customer, array $financial, array $billingState): array
    {
        $restricted = in_array($customer->status, ['suspended', 'expired'], true);
        $automatedRestriction = $customer->suspension_source === 'automation';
        $manualRestriction = $customer->suspension_source === 'manual';
        $allInvoicesSettled = ($financial['open_invoice_count'] ?? 0) === 0
            && (float) ($financial['outstanding_balance'] ?? 0) <= 0;
        $manualOrTechnicalHold = $restricted && !$automatedRestriction && !$manualRestriction;
        $eligible = $restricted && (
            ($automatedRestriction && !$billingState['should_suspend'])
            || ($manualRestriction && $allInvoicesSettled)
        );

        $reason = match (true) {
            !$restricted => 'Customer service is not currently restricted.',
            $manualOrTechnicalHold => 'An unknown or technical hold is present; payment reconciliation will not override it.',
            $manualRestriction && !$allInvoicesSettled => 'The administrator-forced suspension remains because an open invoice balance is still present.',
            $billingState['should_suspend'] => 'An invoice remains beyond the configured grace period.',
            $manualRestriction && $eligible => 'Every open invoice is fully paid; the administrator-forced billing suspension may be restored.',
            $eligible => 'No invoice remains suspension-eligible under the current billing policy.',
            default => 'Service restoration is not eligible under the current billing policy.',
        };

        return [
            'eligible' => $eligible,
            'restricted' => $restricted,
            'automated_restriction' => $automatedRestriction,
            'manual_restriction' => $manualRestriction,
            'manual_or_technical_hold' => $manualOrTechnicalHold,
            'all_invoices_settled' => $allInvoicesSettled,
            'reason' => $reason,
            // A balance may legitimately remain for a not-yet-due invoice.
            // It is reported but does not by itself overrule SolarNet's grace rule.
            'outstanding_balance' => $financial['outstanding_balance'],
        ];
    }

    public function audit(Customer $customer, array $financial, array $eligibility, string $action, string $reason, ?Payment $payment = null, ?Invoice $invoice = null, array $metadata = []): CustomerAccountReconciliation
    {
        return CustomerAccountReconciliation::create([
            'customer_id' => $customer->id,
            'payment_id' => $payment?->id,
            'invoice_id' => $invoice?->id,
            'correlation_id' => $payment?->transaction_id ?: $payment?->payment_number,
            'action' => $action,
            'financial_status' => $financial['financial_status'],
            'service_status' => $customer->status,
            'previous_service_status' => $metadata['previous_service_status'] ?? null,
            'outstanding_balance' => $financial['outstanding_balance'],
            'confirmed_payment_total' => $financial['confirmed_payment_total'],
            'allocated_payment_total' => $financial['allocated_payment_total'],
            'available_credit_total' => $financial['available_credit_total'],
            'restoration_eligible' => $eligibility['eligible'],
            'restoration_status' => $customer->restoration_status,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
