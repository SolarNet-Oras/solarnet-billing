<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

/** Removes unpaid test invoices created on a customer's installation day. */
class RemovePrematureInitialInvoices extends Command
{
    protected $signature = 'billing:remove-premature-initial-invoices
                            {--date= : Installation/issue date to repair (YYYY-MM-DD)}
                            {--invoice=* : Exact unpaid invoice number(s) to remove}
                            {--apply : Persist the previewed removals}';

    protected $description = 'Remove unpaid invoices that were incorrectly created on installation day';

    public function handle(): int
    {
        $query = Invoice::query()
            ->with('customer:id,account_number,installation_date')
            ->whereNotIn('status', ['paid', 'partial'])
            ->whereDoesntHave('payments');
        if ($date = $this->option('date')) $query->whereDate('issue_date', $date);
        if ($numbers = $this->option('invoice')) $query->whereIn('invoice_number', $numbers);

        $invoices = $query->get()->filter(fn (Invoice $invoice) => $this->option('invoice')
            ? true
            : $invoice->customer?->installation_date?->isSameDay($invoice->issue_date)
        );

        foreach ($invoices as $invoice) {
            $this->line("{$invoice->invoice_number}: {$invoice->customer->account_number} due {$invoice->due_date->toDateString()} ({$invoice->total})");
            if ($this->option('apply')) $invoice->delete();
        }

        $this->info($this->option('apply') ? "Removed {$invoices->count()} premature initial invoice(s)." : "Previewed {$invoices->count()} premature initial invoice(s). Re-run with --apply to remove them.");
        return self::SUCCESS;
    }
}
