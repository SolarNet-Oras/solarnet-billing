<?php

namespace App\Console\Commands;

use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\MoneyFlowLogService;
use Illuminate\Console\Command;

class BackfillMoneyFlowLogs extends Command
{
    protected $signature = 'money-flow:backfill {--execute : Persist missing append-only logs}';
    protected $description = 'Audit or backfill immutable money-flow logs without changing financial records';

    public function handle(MoneyFlowLogService $logs): int
    {
        $payments = Payment::withoutGlobalScopes()->count();
        $refunds = PaymentRefund::withoutGlobalScopes()->count();
        $entries = FinancialEntry::withoutGlobalScopes()->count();
        $summary = ['mode' => $this->option('execute') ? 'execute' : 'audit', 'read_only' => ! $this->option('execute'), 'payments' => $payments, 'refunds' => $refunds, 'financial_entries' => $entries];

        if ($this->option('execute')) {
            Payment::withoutGlobalScopes()->cursor()->each(fn (Payment $payment) => $logs->payment($payment));
            PaymentRefund::withoutGlobalScopes()->cursor()->each(fn (PaymentRefund $refund) => $logs->refund($refund));
            FinancialEntry::withoutGlobalScopes()->cursor()->each(fn (FinancialEntry $entry) => $logs->financialEntry($entry));
            $summary['action'] = 'backfill_completed';
        } else {
            $summary['action'] = 'audit_only';
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
