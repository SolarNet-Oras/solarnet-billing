<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

/** Cancels legacy unpaid Company Owned invoices without touching customers or payments. */
class CancelCompanyOwnedInvoices extends Command
{
    protected $signature = 'billing:cancel-company-owned-invoices {--apply : Persist the previewed cancellations}';
    protected $description = 'Cancel unpaid invoices belonging to Company Owned plans';

    public function handle(): int
    {
        $invoices = Invoice::query()
            ->with('customer.servicePlan')
            ->whereIn('status', ['draft', 'sent', 'overdue'])
            ->where('paid_amount', 0)
            ->where('balance', '>', 0)
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->customer?->hasCompanyOwnedPlan());

        foreach ($invoices as $invoice) {
            $this->line("{$invoice->invoice_number}: {$invoice->customer->account_number} ({$invoice->total})");
            if ($this->option('apply')) {
                $invoice->update(['status' => 'cancelled', 'balance' => 0]);
            }
        }

        $this->info($this->option('apply') ? "Cancelled {$invoices->count()} Company Owned invoice(s)." : "Previewed {$invoices->count()} invoice(s). Re-run with --apply to cancel them.");
        return self::SUCCESS;
    }
}
