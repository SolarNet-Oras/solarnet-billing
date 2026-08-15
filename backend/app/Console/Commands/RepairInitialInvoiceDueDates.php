<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** Repairs the historic same-day initial invoices created before the first-month rule. */
class RepairInitialInvoiceDueDates extends Command
{
    protected $signature = 'billing:repair-initial-invoice-due-dates
                            {--date= : Restrict to an installation date (YYYY-MM-DD)}
                            {--apply : Persist the previewed changes}';

    protected $description = 'Move unpaid initial invoices due on installation day to the next monthly anniversary';

    public function handle(): int
    {
        $query = Invoice::query()
            ->with('customer:id,installation_date')
            ->whereColumn('issue_date', 'due_date')
            ->whereIn('status', ['draft', 'sent'])
            ->where('paid_amount', 0)
            ->whereColumn('balance', 'total');

        if ($date = $this->option('date')) {
            $query->whereDate('issue_date', $date);
        }

        $candidates = $query->get()->filter(fn (Invoice $invoice) =>
            $invoice->customer?->installation_date?->isSameDay($invoice->issue_date)
        );

        if ($candidates->isEmpty()) {
            $this->info('No same-day unpaid initial invoices need repair.');
            return self::SUCCESS;
        }

        foreach ($candidates as $invoice) {
            $newDueDate = Carbon::parse($invoice->customer->installation_date)->startOfDay()->addMonthNoOverflow();
            $this->line("{$invoice->invoice_number}: {$invoice->due_date->toDateString()} -> {$newDueDate->toDateString()}");
            if ($this->option('apply')) {
                $invoice->update([
                    'due_date' => $newDueDate,
                    'billing_period_start' => $newDueDate->copy()->subMonthNoOverflow(),
                    'billing_period_end' => $newDueDate,
                    'recurring_cycle_date' => $newDueDate,
                ]);
            }
        }

        $this->info($this->option('apply') ? "Repaired {$candidates->count()} initial invoice(s)." : "Previewed {$candidates->count()} invoice(s). Re-run with --apply to save.");
        return self::SUCCESS;
    }
}
