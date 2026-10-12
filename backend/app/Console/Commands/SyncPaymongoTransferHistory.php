<?php

namespace App\Console\Commands;

use App\Models\PaymongoOutwardTransfer;
use App\Services\PaymongoOutwardTransferService;
use App\Services\PaymongoTransferHistoryService;
use Illuminate\Console\Command;

class SyncPaymongoTransferHistory extends Command
{
    private const CONFIRMATION = 'IMPORT PAYMONGO TRANSFER HISTORY';

    protected $signature = 'paymongo:sync-transfer-history
                            {--from= : First provider transaction date, YYYY-MM-DD}
                            {--to= : Last provider transaction date, YYYY-MM-DD}
                            {--execute : Persist history and create missing Daily Operations expenses}
                            {--confirm= : Exact execution confirmation phrase}';

    protected $description = 'Audit or import successful PayMongo Send Funds history into Daily Operations';

    public function handle(PaymongoTransferHistoryService $history, PaymongoOutwardTransferService $transfers): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $this->error('Both --from and --to are required in YYYY-MM-DD format.');
            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        if ($execute && ! hash_equals(self::CONFIRMATION, (string) $this->option('confirm'))) {
            $this->error('Execution stopped. Use --confirm="'.self::CONFIRMATION.'".');
            return self::FAILURE;
        }

        try {
            $resources = $history->outgoing($from, $to);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $details = [];
        $imported = 0;
        foreach ($resources as $resource) {
            $id = (string) data_get($resource, 'id');
            $status = $history->status($resource);
            $existing = PaymongoOutwardTransfer::where('provider_transfer_id', $id)->first();
            $successful = in_array($status, ['successful', 'succeeded', 'success', 'completed'], true);
            $action = $existing?->financial_entry_id
                ? 'already_recorded'
                : ($successful ? ($execute ? 'imported' : 'would_import') : 'skipped_not_successful');

            if ($execute && ! $existing?->financial_entry_id) {
                $eventType = $successful ? 'transfer.outward.succeeded' : 'transfer.outward.'.$status;
                $transfers->receive($resource, 'history_'.$id, $eventType);
                if (PaymongoOutwardTransfer::where('provider_transfer_id', $id)->whereNotNull('financial_entry_id')->exists()) $imported++;
            }

            $details[] = [
                'provider_transfer_id' => $id,
                'status' => $status,
                'provider_created_at' => $this->providerTimestamp(data_get($resource, 'attributes.created_at')),
                'provider_updated_at' => $this->providerTimestamp(data_get($resource, 'attributes.updated_at')),
                'amount' => ((int) data_get($resource, 'attributes.amount', 0)) / 100,
                'fee' => ((int) data_get($resource, 'attributes.fee', 0)) / 100,
                'reference_number' => data_get($resource, 'attributes.reference_number'),
                'provider_reference_number' => data_get($resource, 'attributes.provider_reference_number'),
                'recipient' => data_get($resource, 'attributes.receiver.bank_account_name') ?: data_get($resource, 'attributes.destination_account.name'),
                'institution' => data_get($resource, 'attributes.receiver.bank_name') ?: data_get($resource, 'attributes.destination_account.bank_name'),
                'recipient_account_last4' => $this->accountLast4($resource),
                'action' => $action,
            ];
        }

        $report = [
            'mode' => $execute ? 'execute' : 'audit',
            'date_range' => [$from, $to],
            'provider_outgoing_transactions' => count($resources),
            'successful_missing' => collect($details)->where('action', $execute ? 'imported' : 'would_import')->count(),
            'already_recorded' => collect($details)->where('action', 'already_recorded')->count(),
            'imported_daily_operations' => $imported,
            'details' => $details,
        ];
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function providerTimestamp(mixed $value): ?string
    {
        if (! $value) return null;
        $date = is_numeric($value)
            ? \Illuminate\Support\Carbon::createFromTimestamp((int) $value)
            : \Illuminate\Support\Carbon::parse($value);
        return $date->timezone('Asia/Manila')->toIso8601String();
    }

    private function accountLast4(array $resource): ?string
    {
        $account = (string) (data_get($resource, 'attributes.receiver.bank_account_number')
            ?: data_get($resource, 'attributes.destination_account.account_number')
            ?: '');
        return $account === '' ? null : substr($account, -4);
    }
}
