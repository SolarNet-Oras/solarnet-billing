<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

/** Removes legacy unpaid Company Owned invoices without touching customers or payments. */
class CancelCompanyOwnedInvoices extends Command
{
    protected $signature = 'billing:cancel-company-owned-invoices {--apply : Persist the previewed cancellations}';
    protected $description = 'Remove unpaid invoices belonging to Company Owned plans';

    public function handle(): int
    {
        $invoices = Invoice::query()
            ->with('customer.servicePlan')
            ->whereNotIn('status', ['paid', 'partial'])
            ->whereDoesntHave('payments')
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->customer?->hasCompanyOwnedPlan());

        foreach ($invoices as $invoice) {
            $this->line("{$invoice->invoice_number}: {$invoice->customer->account_number} ({$invoice->total})");
            if ($this->option('apply')) $invoice->delete();
        }

        $this->info($this->option('apply') ? "Removed {$invoices->count()} Company Owned invoice(s)." : "Previewed {$invoices->count()} invoice(s). Re-run with --apply to remove them.");
        return self::SUCCESS;
    }
}
