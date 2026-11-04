<?php

namespace App\Console\Commands;

use App\Models\CollectorCustomerAssignment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AutomaticCollectionPlannerService;
use App\Services\CollectionAddressNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PreviewAutomaticCustomerAssignments extends Command
{
    protected $signature = 'collection:preview-customer-assignments
        {--date= : Manila workload date; defaults to today}
        {--include-inactive : Include inactive, disconnected, and expired customers as exceptions}';

    protected $description = 'Read-only preview of territory-aware customer assignments and collector workload';

    public function handle(
        AutomaticCollectionPlannerService $planner,
        CollectionAddressNormalizer $normalizer,
    ): int {
        $timezone = (string) config('collection_planner.timezone', 'Asia/Manila');
        $date = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('date'), $timezone)->startOfDay()
            : now($timezone)->startOfDay();

        $unassigned = Customer::query()
            ->whereDoesntHave('collectorAssignment', fn ($query) => $query->whereNull('unassigned_at'))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'account_number', 'address', 'gps_coordinates', 'status']);
        $assignable = $unassigned->whereIn('status', ['active', 'suspended'])->values();
        $excluded = $unassigned->whereNotIn('status', ['active', 'suspended'])->values();
        $preview = $planner->previewAssignments($assignable->pluck('id'));
        $eligibleToday = $planner->eligibleCustomerIds($date);
        $proposals = collect($preview['proposals']);
        $collectors = User::query()->eligibleFieldCollector()
            ->orderBy('name')->get(['id', 'name']);

        $workloads = $collectors->map(function (User $collector) use ($proposals, $eligibleToday, $date): array {
            $existingIds = CollectorCustomerAssignment::query()->where('collector_id', $collector->id)
                ->whereNull('unassigned_at')->pluck('customer_id');
            $proposed = $proposals->where('collector.id', $collector->id);
            $proposedIds = $proposed->pluck('customer.id');
            $allIds = $existingIds->merge($proposedIds)->unique();
            $eligibleIds = $allIds->intersect($eligibleToday);
            $overdue = Invoice::query()->whereIn('customer_id', $allIds)->where('balance', '>', 0)
                ->whereIn('status', ['sent', 'partial', 'overdue'])->whereDate('due_date', '<', $date);

            return [
                'collector_id' => $collector->id,
                'collector' => $collector->name,
                'existing_assignments' => $existingIds->count(),
                'proposed_assignments' => $proposedIds->count(),
                'total_after_preview' => $allIds->count(),
                'eligible_visits_today' => $eligibleIds->count(),
                'overdue_invoice_count' => (clone $overdue)->count(),
                'overdue_balance' => round((float) (clone $overdue)->sum('balance'), 2),
            ];
        })->values();

        $assignmentRows = $proposals->map(function (array $proposal) use ($normalizer, $eligibleToday): array {
            $location = $normalizer->analyze($proposal['customer']->address);
            $authorizedLocation = $proposal['territory']?->address_pattern;
            $barangay = $location['barangay'] ?? ($authorizedLocation ? trim($authorizedLocation) : null);
            $addressReliable = $location['reliable'] || filled($authorizedLocation);

            return [
                'customer_id' => $proposal['customer']->id,
                'customer' => $proposal['customer']->full_name,
                'account_number' => $proposal['customer']->account_number,
                'original_address' => $proposal['customer']->address,
                'barangay' => $barangay,
                'phase' => $location['phase'],
                'has_verified_gps' => filled($proposal['customer']->gps_coordinates),
                'collector_id' => $proposal['collector']->id,
                'collector' => $proposal['collector']->name,
                'territory' => $proposal['territory']?->name,
                'address_reliable' => $addressReliable,
                'address_warning' => $addressReliable ? null : $location['ambiguous_reason'],
                'eligible_visit_today' => $eligibleToday->contains($proposal['customer']->id),
                'action' => 'would_assign',
            ];
        })->values();

        $exceptions = collect($preview['exceptions'])->map(function (array $exception) use ($normalizer): array {
            $location = $normalizer->analyze($exception['address'] ?? null);

            return [...$exception, 'barangay' => $location['barangay'], 'phase' => $location['phase'],
                'ambiguous_reason' => $location['ambiguous_reason'], 'action' => 'requires_review'];
        });
        if ($this->option('include-inactive')) {
            $exceptions = $exceptions->merge($excluded->map(fn (Customer $customer): array => [
                'customer_id' => $customer->id,
                'customer' => $customer->full_name,
                'account_number' => $customer->account_number,
                'address' => $customer->address,
                'reason' => 'customer_status_'.$customer->status,
                'action' => 'excluded_no_assignment',
            ]));
        }

        $territoryCoverage = $assignmentRows->groupBy(fn ($row) => implode('|', [
            $row['collector_id'], $row['barangay'] ?? 'Unverified', $row['phase'] ?? 'No phase',
        ]))->map(fn ($rows) => [
            'collector' => $rows->first()['collector'],
            'barangay' => $rows->first()['barangay'],
            'phase' => $rows->first()['phase'],
            'customer_count' => $rows->count(),
        ])->values();

        $report = [
            'mode' => 'read_only_preview',
            'database_changes' => 0,
            'plan_date' => $date->toDateString(),
            'totals' => [
                'unassigned_customers' => $unassigned->count(),
                'assignable_customers' => $assignable->count(),
                'collectors_available' => $collectors->count(),
                'would_assign' => $assignmentRows->count(),
                'assignment_exceptions' => $exceptions->count(),
                'ambiguous_addresses' => $assignmentRows->where('address_reliable', false)->count()
                    + $exceptions->whereNotNull('ambiguous_reason')->count(),
                'excluded_statuses' => $excluded->count(),
            ],
            'collector_workload' => $workloads,
            'territory_coverage' => $territoryCoverage,
            'proposed_assignments' => $assignmentRows,
            'exceptions' => $exceptions->values(),
            'proposed_database_migrations' => [],
            'collector_access_effects' => [
                'preview_only' => true,
                'new_customer_visibility' => 0,
                'existing_assignments_preserved' => true,
                'unauthorized_territories_exposed' => false,
            ],
            'safety' => [
                'assignments_created' => 0,
                'plans_generated' => 0,
                'invoices_modified' => 0,
                'payments_modified' => 0,
                'due_dates_modified' => 0,
            ],
        ];

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
