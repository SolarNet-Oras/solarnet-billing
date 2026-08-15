<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\FinancialEntry;
use App\Models\HistoricalCleanupAudit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Safely removes only selected historical child records. This service must never
 * delete customers, users, routers, leases, service plans, or active accounting data.
 */
class HistoricalDataCleanupService
{
    public const CONFIRMATION_PHRASE = 'DELETE HISTORICAL DATA';
    private const CACHE_PREFIX = 'historical-cleanup-preview:';
    private const TOKEN_TTL_SECONDS = 600;

    public const MODULES = [
        'past_transactions' => 'Past transaction records',
        'daily_operations' => 'Daily-operation entries',
        'invoices' => 'Historical paid/cancelled invoices',
        'tickets' => 'Closed repair tickets',
        'liquidations' => 'Historical submitted/received remittances',
        'installation_applications' => 'Finished/rejected installation applications',
    ];

    public function preview(User $user, array $input): array
    {
        $modules = $this->validatedModules($input['modules'] ?? []);
        [$from, $to] = $this->dateRange($input);
        $summary = $this->summary($modules, $from, $to);
        $token = (string) Str::uuid();

        Cache::put(self::CACHE_PREFIX.$token, [
            'user_id' => $user->id,
            'modules' => $modules,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'summary' => $summary,
        ], self::TOKEN_TTL_SECONDS);

        return ['preview_token' => $token, 'expires_in_seconds' => self::TOKEN_TTL_SECONDS] + $summary;
    }

    public function execute(User $user, string $token, string $confirmation, ?string $ipAddress = null): HistoricalCleanupAudit
    {
        if ($confirmation !== self::CONFIRMATION_PHRASE) {
            throw new RuntimeException('Enter the exact confirmation phrase before historical cleanup can run.');
        }

        $preview = Cache::get(self::CACHE_PREFIX.$token);
        if (! is_array($preview) || ($preview['user_id'] ?? null) !== $user->id) {
            throw new RuntimeException('This cleanup preview has expired or does not belong to your account. Create a new preview.');
        }

        $from = Carbon::parse($preview['from_date'])->startOfDay();
        $to = Carbon::parse($preview['to_date'])->endOfDay();
        $modules = $this->validatedModules($preview['modules']);
        $before = Customer::withTrashed()->count();

        $audit = HistoricalCleanupAudit::create([
            'user_id' => $user->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'modules' => $modules,
            'summary' => $preview['summary'],
            'customer_count_before' => $before,
            'customer_records_deleted' => 0,
            'status' => 'running',
            'ip_address' => $ipAddress,
        ]);

        try {
            $deleted = DB::transaction(function () use ($modules, $from, $to, $before): array {
                $result = $this->deleteEligible($modules, $from, $to);
                $after = Customer::withTrashed()->count();

                if ($after !== $before) {
                    throw new RuntimeException('Cleanup stopped because customer record count changed. No cleanup was committed.');
                }

                return $result;
            });

            $audit->update([
                'summary' => $this->summary($modules, $from, $to) + ['deleted' => $deleted],
                'customer_count_after' => Customer::withTrashed()->count(),
                'customer_records_deleted' => 0,
                'status' => 'completed',
            ]);
            Cache::forget(self::CACHE_PREFIX.$token);

            return $audit->fresh(['user']);
        } catch (\Throwable $exception) {
            $audit->update([
                'customer_count_after' => Customer::withTrashed()->count(),
                'customer_records_deleted' => 0,
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function summary(array $modules, Carbon $from, Carbon $to): array
    {
        $scopes = $this->scopes($modules, $from, $to);
        $items = [];
        foreach ($scopes as $module => $scope) {
            $items[$module] = [
                'label' => self::MODULES[$module],
                'eligible' => (clone $scope)->count(),
                'blocked' => $this->blockedCount($module, $modules, $from, $to),
            ];
        }

        return [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'modules' => $items,
            'customer_records_deleted' => 0,
            'warning' => 'Customers, users, routers, leases, configuration, active invoices, and advance-credit payments are never deleted. To remove linked test payments, select Past transactions together with the matching Invoices and/or Liquidations module.',
        ];
    }

    private function deleteEligible(array $modules, Carbon $from, Carbon $to): array
    {
        $deleted = [];
        $scopes = $this->scopes($modules, $from, $to);

        // Child/history tables first. A Ticket delete only cascades to its own
        // comments/history; it can never cascade upward to a customer or user.
        foreach (['daily_operations', 'tickets', 'installation_applications'] as $module) {
            if (isset($scopes[$module])) $deleted[$module] = $this->deleteScope($scopes[$module]);
        }

        // A remittance with payments can be deleted only in coordinated test-data
        // cleanup, so payment rows are not left pointing to a removed header.
        if (isset($scopes['liquidations'])) $deleted['liquidations'] = $this->deleteScope($scopes['liquidations']);

        // After the remittance headers are deleted, eligible linked payments have
        // a null remittance_id and can be removed. Invoiced payments require the
        // Invoices module too; this prevents stale paid_amount/balance values.
        if (isset($scopes['past_transactions'])) {
            $deleted['past_transactions'] = $this->deleteScope($this->transactionScope($modules, $from, $to));
        }

        // Paid test invoices are eligible only after their selected-range payments
        // were removed above. Current/partial/unpaid invoices remain protected.
        if (isset($scopes['invoices'])) $deleted['invoices'] = $this->deleteScope($this->invoiceScope($modules, $from, $to));

        return $deleted;
    }

    private function scopes(array $modules, Carbon $from, Carbon $to): array
    {
        $scopes = [];
        foreach ($modules as $module) {
            $scopes[$module] = match ($module) {
                'past_transactions' => $this->transactionScope($modules, $from, $to),
                'daily_operations' => FinancialEntry::query()->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]),
                'invoices' => $this->invoiceScope($modules, $from, $to),
                'tickets' => Ticket::query()->where('ticket_type', '!=', 'installation')->where('status', 'closed')->whereBetween('closed_at', [$from, $to]),
                'liquidations' => $this->liquidationScope($modules, $from, $to),
                'installation_applications' => Ticket::query()->where('ticket_type', 'installation')->whereIn('workflow_status', ['registered', 'rejected', 'cancelled'])->whereBetween('created_at', [$from, $to]),
            };
        }
        return $scopes;
    }

    private function blockedCount(string $module, array $modules, Carbon $from, Carbon $to): int
    {
        return match ($module) {
            'past_transactions' => Payment::query()->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
                ->whereNotIn('id', $this->transactionScope($modules, $from, $to)->select('id'))->count(),
            'invoices' => Invoice::query()->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
                ->whereNotIn('id', $this->invoiceScope($modules, $from, $to)->select('id'))->count(),
            'liquidations' => Remittance::query()->whereBetween('submitted_at', [$from, $to])
                ->whereNotIn('id', $this->liquidationScope($modules, $from, $to)->select('id'))->count(),
            default => 0,
        };
    }

    private function transactionScope(array $modules, Carbon $from, Carbon $to)
    {
        return Payment::query()
            ->whereNotIn('id', CustomerCredit::query()->whereNotNull('payment_id')->select('payment_id'))
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query) use ($modules, $from, $to) {
                $query->whereNull('invoice_id');
                if (in_array('invoices', $modules, true)) {
                    $query->orWhereHas('invoice', fn ($invoice) => $invoice->whereIn('status', ['paid', 'cancelled']));
                }
            })
            ->where(function ($query) use ($modules, $from, $to) {
                $query->whereNull('remittance_id');
                if (in_array('liquidations', $modules, true)) {
                    $query->orWhereHas('remittance', fn ($remittance) => $remittance->whereBetween('submitted_at', [$from, $to]));
                }
            });
    }

    private function invoiceScope(array $modules, Carbon $from, Carbon $to)
    {
        $scope = Invoice::query()->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()]);
        if (in_array('past_transactions', $modules, true)) {
            return $scope->whereIn('status', ['paid', 'cancelled'])
                ->whereDoesntHave('payments', fn ($payment) => $payment->whereNotIn('id', $this->transactionScope($modules, $from, $to)->select('id')));
        }
        return $scope->where('status', 'cancelled')->whereDoesntHave('payments');
    }

    private function liquidationScope(array $modules, Carbon $from, Carbon $to)
    {
        $scope = Remittance::query()->whereIn('status', ['submitted', 'received', 'discrepancy'])->whereBetween('submitted_at', [$from, $to]);
        if (in_array('past_transactions', $modules, true)) {
            return $scope->whereDoesntHave('payments', fn ($payment) => $payment->whereNotIn('id', $this->transactionScope($modules, $from, $to)->select('id')));
        }
        return $scope->whereDoesntHave('payments');
    }

    private function deleteScope($scope): int
    {
        $count = 0;
        $scope->chunkById(200, function ($models) use (&$count) {
            foreach ($models as $model) { $model->delete(); $count++; }
        });
        return $count;
    }

    private function validatedModules(array $modules): array
    {
        $modules = array_values(array_unique(array_filter($modules, fn ($module) => array_key_exists($module, self::MODULES))));
        if ($modules === []) throw new RuntimeException('Select at least one cleanup module.');
        return $modules;
    }

    private function dateRange(array $input): array
    {
        $from = Carbon::parse($input['from_date'] ?? null)->startOfDay();
        $to = Carbon::parse($input['to_date'] ?? null)->endOfDay();
        if ($to->lt($from)) throw new RuntimeException('The end date must be on or after the start date.');
        return [$from, $to];
    }
}
