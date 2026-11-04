<?php

namespace App\Console\Commands;

use App\Services\CollectionAssignmentRoutingService;
use Illuminate\Console\Command;

class ApplyCollectionAssignmentRoutingPolicy extends Command
{
    protected $signature = 'collection:assign-routing-policy
        {--execute : Apply the previewed customer assignments}
        {--confirm= : Must equal ASSIGN COLLECTION ROUTING POLICY}';

    protected $description = 'Audit or apply Edmar first-attempt and Nelson follow-up customer assignments';

    public function handle(CollectionAssignmentRoutingService $routing): int
    {
        $rows = $routing->preview();
        $execute = (bool) $this->option('execute');

        if ($execute && $this->option('confirm') !== 'ASSIGN COLLECTION ROUTING POLICY') {
            $this->error('Execution stopped: exact confirmation is required.');
            return self::FAILURE;
        }

        $changed = $execute ? $routing->apply($rows) : 0;
        $report = [
            'mode' => $execute ? 'execute' : 'audit',
            'policy' => [
                'first_attempt' => config('collection_planner.primary_collector_name'),
                'second_and_third_attempts' => config('collection_planner.followup_collector_name'),
                'generic_collector_account' => 'admin_view_only',
            ],
            'active_or_suspended_customers' => $rows->count(),
            'would_change' => $rows->where('action', 'assign_or_reassign')->count(),
            'unchanged' => $rows->where('action', 'keep_assignment')->count(),
            'assignments_changed' => $changed,
            'by_target' => $rows->groupBy('target_collector')->map->count()->all(),
            'details' => $rows->values()->all(),
            'financial_records_modified' => 0,
        ];
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
