<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\FinancialEntry;
use App\Models\HistoricalCleanupAudit;
use App\Models\Invoice;
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
        'daily_operations' => 'Daily-operation entries',
        'invoices' => 'Cancelled unpaid invoices',
        'tickets' => 'Closed repair tickets',
        'liquidations' => 'Unlinked completed remittances',
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
                'blocked' => $this->blockedCount($module, $from, $to),
            ];
        }

        return [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'modules' => $items,
            'customer_records_deleted' => 0,
            'warning' => 'Only the eligible closed or cancelled records shown here can be removed. Payments, active invoices, customers, users, routers, leases, and configuration are never deleted.',
        ];
    }

    private function deleteEligible(array $modules, Carbon $from, Carbon $to): array
    {
        $deleted = [];
        foreach ($this->scopes($modules, $from, $to) as $module => $scope) {
            $count = 0;
            $scope->chunkById(200, function ($models) use (&$count) {
                foreach ($models as $model) {
                    $model->delete();
                    $count++;
                }
            });
            $deleted[$module] = $count;
        }
        return $deleted;
    }

    private function scopes(array $modules, Carbon $from, Carbon $to): array
    {
        $scopes = [];
        foreach ($modules as $module) {
            $scopes[$module] = match ($module) {
                'daily_operations' => FinancialEntry::query()->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]),
                'invoices' => Invoice::query()->where('status', 'cancelled')->whereDoesntHave('payments')->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()]),
                'tickets' => Ticket::query()->where('ticket_type', '!=', 'installation')->where('status', 'closed')->whereBetween('closed_at', [$from, $to]),
                'liquidations' => Remittance::query()->whereIn('status', ['received', 'discrepancy'])->whereDoesntHave('payments')->whereBetween('submitted_at', [$from, $to]),
                'installation_applications' => Ticket::query()->where('ticket_type', 'installation')->whereIn('workflow_status', ['registered', 'rejected', 'cancelled'])->whereBetween('created_at', [$from, $to]),
            };
        }
        return $scopes;
    }

    private function blockedCount(string $module, Carbon $from, Carbon $to): int
    {
        return match ($module) {
            'invoices' => Invoice::query()->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->where(fn ($q) => $q->where('status', '!=', 'cancelled')->orWhereHas('payments'))->count(),
            'liquidations' => Remittance::query()->whereBetween('submitted_at', [$from, $to])->whereHas('payments')->count(),
            default => 0,
        };
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
