<?php

namespace App\Console\Commands;

use App\Models\BillingSmsNotification;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepairOpenInvoiceDueDates extends Command
{
    protected $signature = 'billing:repair-open-invoice-due-dates
                            {--from= : Current due date on or after YYYY-MM-DD}
                            {--to= : Current due date on or before YYYY-MM-DD}
                            {--apply : Apply the previewed corrections}
                            {--confirm= : Must equal REPAIR OPEN INVOICE DUE DATES}';

    protected $description = 'Audit and repair open manual/recurring invoice due dates from customer billing cycles';

    public function handle(): int
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $query = Invoice::query()
            ->with('customer:id,account_number,full_name,billing_cycle_day,installation_date')
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereIn('generation_source', ['manual', 'recurring'])
            ->when($this->option('from'), fn ($q, $date) => $q->whereDate('due_date', '>=', $date))
            ->when($this->option('to'), fn ($q, $date) => $q->whereDate('due_date', '<=', $date))
            ->orderBy('due_date');

        $repairs = [];
        $blocked = [];
        foreach ($query->get() as $invoice) {
            if (! $invoice->customer) continue;

            $expected = $invoice->generation_source === 'recurring' && $invoice->recurring_cycle_date
                ? $invoice->recurring_cycle_date->copy()->startOfDay()
                : $invoice->customer->nextBillingDueDate($invoice->issue_date?->copy()->startOfDay());
            if (! $expected || $invoice->due_date?->isSameDay($expected)) continue;

            $row = [
                'invoice' => $invoice->invoice_number,
                'account' => $invoice->customer->account_number,
                'customer' => $invoice->customer->full_name,
                'source' => $invoice->generation_source,
                'old_due' => $invoice->due_date?->toDateString(),
                'correct_due' => $expected->toDateString(),
            ];
            if ($invoice->finalGracePeriodWarnings()->exists()) {
                $blocked[] = $row + ['reason' => 'final grace-period audit exists'];
                continue;
            }
            $repairs[] = ['invoice' => $invoice, 'expected' => $expected, 'row' => $row];
        }

        $this->table(
            ['Invoice', 'Account', 'Customer', 'Source', 'Current due', 'Correct due'],
            array_map(fn (array $repair) => array_values($repair['row']), $repairs),
        );
        $this->info(count($repairs).' correction(s) eligible; '.count($blocked).' blocked by immutable final-warning audits.');

        if (! $this->option('apply')) {
            $this->warn('Preview only. No invoice, payment, allocation, customer, reminder, or network record was changed.');
            return self::SUCCESS;
        }
        if ($this->option('confirm') !== 'REPAIR OPEN INVOICE DUE DATES') {
            $this->error('Stopped. Use --confirm="REPAIR OPEN INVOICE DUE DATES" after reviewing the preview.');
            return self::FAILURE;
        }

        $changed = 0;
        foreach ($repairs as $repair) {
            /** @var Invoice $invoice */
            $invoice = $repair['invoice'];
            /** @var Carbon $expected */
            $expected = $repair['expected'];
            DB::transaction(function () use ($invoice, $expected, $timezone, &$changed): void {
                $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                if ($locked->balance <= 0 || ! in_array($locked->status, ['sent', 'partial', 'overdue'], true)) return;

                $oldDue = $locked->due_date?->toDateString();
                $status = $locked->status;
                if ($status !== 'partial') {
                    $status = $expected->lt(now($timezone)->startOfDay()) ? 'overdue' : 'sent';
                }
                $locked->forceFill(['due_date' => $expected, 'status' => $status])->save();

                // Pending audit rows may follow the corrected schedule. A sent
                // provider record is historical evidence and is never rewritten.
                BillingSmsNotification::query()
                    ->where('invoice_id', $locked->id)
                    ->whereIn('status', ['queued', 'failed'])
                    ->update(['due_date' => $expected, 'updated_at' => now()]);

                Log::notice('Open invoice due date repaired from customer billing cycle', [
                    'invoice_id' => $locked->id,
                    'invoice_number' => $locked->invoice_number,
                    'customer_id' => $locked->customer_id,
                    'old_due_date' => $oldDue,
                    'new_due_date' => $expected->toDateString(),
                    'generation_source' => $locked->generation_source,
                ]);
                $changed++;
            });
        }

        $this->info("{$changed} open invoice due date(s) repaired. Amounts, payments, allocations, and customer cycles were unchanged.");
        return self::SUCCESS;
    }
}
