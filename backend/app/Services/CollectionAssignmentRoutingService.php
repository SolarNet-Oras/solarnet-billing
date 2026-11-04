<?php

namespace App\Services;

use App\Models\CollectionVisit;
use App\Models\CollectorCustomerAssignment;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CollectionAssignmentRoutingService
{
    private const SUCCESS_OUTCOMES = [
        'payment_collected',
        'partial_payment_collected',
        'customer_already_paid',
    ];

    public function collectors(): array
    {
        return [
            'primary' => $this->collector((string) config('collection_planner.primary_collector_name')),
            'followup' => $this->collector((string) config('collection_planner.followup_collector_name')),
        ];
    }

    public function preview(): Collection
    {
        $collectors = $this->collectors();
        $failedAttempts = CollectionVisit::query()
            ->whereNotNull('completed_at')
            ->whereNotIn('outcome', self::SUCCESS_OUTCOMES)
            ->selectRaw('customer_id, count(*) as failed_attempts')
            ->groupBy('customer_id')
            ->pluck('failed_attempts', 'customer_id');

        return Customer::query()->whereIn('status', ['active', 'suspended'])
            ->with('collectorAssignment.collector:id,name')
            ->orderBy('full_name')->get()
            ->map(function (Customer $customer) use ($collectors, $failedAttempts): array {
                $attempts = (int) ($failedAttempts[$customer->id] ?? 0);
                $target = $attempts === 0 ? $collectors['primary'] : $collectors['followup'];
                $current = $customer->collectorAssignment?->collector;

                return [
                    'customer_id' => $customer->id,
                    'customer' => $customer->full_name,
                    'account_number' => $customer->account_number,
                    'status' => $customer->status,
                    'failed_attempts' => $attempts,
                    'next_attempt_number' => $attempts + 1,
                    'current_collector' => $current?->name,
                    'target_collector' => $target->name,
                    'target_collector_id' => $target->id,
                    'action' => $current?->id === $target->id ? 'keep_assignment' : 'assign_or_reassign',
                ];
            });
    }

    public function apply(Collection $rows, ?string $actorId = null): int
    {
        return DB::transaction(function () use ($rows, $actorId): int {
            $changed = 0;
            foreach ($rows->where('action', 'assign_or_reassign') as $row) {
                CollectorCustomerAssignment::updateOrCreate(
                    ['customer_id' => $row['customer_id']],
                    [
                        'collector_id' => $row['target_collector_id'],
                        'assigned_by' => $actorId,
                        'territory_label' => $row['failed_attempts'] > 0
                            ? 'Follow-up attempts 2–3'
                            : 'Primary first attempt',
                        'assigned_at' => now(),
                        'unassigned_at' => null,
                    ],
                );
                $changed++;
            }

            return $changed;
        });
    }

    private function collector(string $name): User
    {
        $collector = User::query()->eligibleFieldCollector()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
            ->first();

        if (! $collector) {
            throw new RuntimeException("Eligible field collector not found: {$name}");
        }

        return $collector;
    }
}
