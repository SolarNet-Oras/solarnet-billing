<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BackfillSolarNetOperationalOwnership extends Command
{
    private const CONFIRMATION = 'ASSIGN LEGACY OPERATIONAL RECORDS TO SOLARNET';

    protected $signature = 'saas:backfill-solarnet-operational-ownership
                            {--execute : Assign currently unowned operational root records}
                            {--confirm= : Exact execution confirmation phrase}';

    protected $description = 'Audit or transactionally assign legacy operational root records to SolarNet';

    /** @var list<string> */
    private array $tables = [
        'collection_plans',
        'daily_cash_counts',
        'staff_attendance_records',
        'staff_payroll_disbursements',
        'staff_leave_requests',
        'dhcp_leases',
        'tickets',
        'operations_map_assets',
        'sms_advisory_campaigns',
        'routers',
        'olt_devices',
        'wireguard_peers',
        'activity_logs',
        'automation_logs',
        'settings',
    ];

    /** @var list<array{child:string, foreign_key:string, parent:string}> */
    private array $relationships = [
        ['child' => 'dhcp_leases', 'foreign_key' => 'customer_id', 'parent' => 'customers'],
        ['child' => 'dhcp_leases', 'foreign_key' => 'router_id', 'parent' => 'routers'],
        ['child' => 'tickets', 'foreign_key' => 'customer_id', 'parent' => 'customers'],
        ['child' => 'tickets', 'foreign_key' => 'router_id', 'parent' => 'routers'],
        ['child' => 'tickets', 'foreign_key' => 'sms_advisory_campaign_id', 'parent' => 'sms_advisory_campaigns'],
        ['child' => 'sms_advisory_campaigns', 'foreign_key' => 'router_id', 'parent' => 'routers'],
        ['child' => 'olt_devices', 'foreign_key' => 'router_id', 'parent' => 'routers'],
        ['child' => 'wireguard_peers', 'foreign_key' => 'router_id', 'parent' => 'routers'],
        ['child' => 'staff_payroll_disbursements', 'foreign_key' => 'financial_entry_id', 'parent' => 'financial_entries'],
    ];

    /** @var list<array{table:string, user_key:string}> */
    private array $membershipRelationships = [
        ['table' => 'collection_plans', 'user_key' => 'collector_id'],
        ['table' => 'staff_attendance_records', 'user_key' => 'user_id'],
        ['table' => 'staff_payroll_disbursements', 'user_key' => 'user_id'],
        ['table' => 'staff_leave_requests', 'user_key' => 'user_id'],
    ];

    public function handle(): int
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                $this->error("Missing {$table}.tenant_id; run the operational ownership migration first.");

                return self::FAILURE;
            }
        }

        $tenant = Tenant::query()
            ->where('slug', config('saas.primary_tenant_slug'))
            ->where('is_primary', true)
            ->where('subscription_exempt', true)
            ->first();

        if (! $tenant) {
            $this->error('Primary subscription-exempt SolarNet tenant was not found.');

            return self::FAILURE;
        }

        $preview = $this->preview($tenant->id);
        $this->line(json_encode([
            'mode' => $this->option('execute') ? 'execute_requested' : 'audit',
            'read_only' => ! $this->option('execute'),
            'tenant' => $tenant->only(['id', 'slug', 'name', 'is_primary', 'subscription_exempt']),
            ...$preview,
            'navigation_remains_staged' => true,
            'action' => $this->option('execute') ? 'awaiting_confirmation' : 'no_changes_audit_only',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! $this->option('execute')) {
            return self::SUCCESS;
        }

        if ($this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Backfill stopped: exact confirmation phrase is required.');

            return self::FAILURE;
        }

        if (! $preview['safe_for_execution']) {
            $this->error('Backfill stopped: operational relationship preconditions failed.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($tenant): array {
                $this->lockTables();
                $lockedPreview = $this->preview($tenant->id);
                if (! $lockedPreview['safe_for_execution']) {
                    throw new RuntimeException('Preconditions changed after table locks were acquired.');
                }

                $before = $this->invariantSnapshot();
                $updated = [];
                foreach ($this->tables as $table) {
                    $updated[$table] = DB::table($table)
                        ->whereNull('tenant_id')
                        ->update(['tenant_id' => $tenant->id]);
                }

                $after = $this->invariantSnapshot();
                if ($before !== $after) {
                    throw new RuntimeException('Operational or financial invariants changed; transaction rolled back.');
                }

                $verification = $this->preview($tenant->id);
                if ($verification['unassigned_records'] !== 0
                    || $verification['relationship_integrity_failures'] !== 0) {
                    throw new RuntimeException('Post-backfill verification failed; transaction rolled back.');
                }

                return compact('updated', 'before', 'after', 'verification');
            }, 3);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([
            'mode' => 'execute',
            'tenant_id' => $tenant->id,
            'updated' => $result['updated'],
            'invariants_preserved' => $result['before'] === $result['after'],
            'verification' => $result['verification'],
            'navigation_remains_staged' => true,
            'action' => 'operational_root_backfill_completed_and_verified',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function preview(string $tenantId): array
    {
        $tables = collect($this->tables)->mapWithKeys(function (string $table) use ($tenantId): array {
            $query = DB::table($table);

            return [$table => [
                'total' => (clone $query)->count(),
                'unassigned' => (clone $query)->whereNull('tenant_id')->count(),
                'assigned_to_solarnet' => (clone $query)->where('tenant_id', $tenantId)->count(),
                'assigned_to_other_tenants' => (clone $query)
                    ->whereNotNull('tenant_id')->where('tenant_id', '!=', $tenantId)->count(),
            ]];
        });
        $integrity = $this->integritySnapshot($tenantId);
        $failures = array_sum($integrity);

        return [
            'tables' => $tables->all(),
            'integrity' => $integrity,
            'unassigned_records' => $tables->sum('unassigned'),
            'records_assigned_to_other_tenants' => $tables->sum('assigned_to_other_tenants'),
            'relationship_integrity_failures' => $failures,
            'safe_for_execution' => $failures === 0,
        ];
    }

    /** @return array<string, int> */
    private function integritySnapshot(string $tenantId): array
    {
        $integrity = [];
        foreach ($this->relationships as $relationship) {
            $key = $relationship['child'].'_'.$relationship['foreign_key'];
            $child = $relationship['child'];
            $parent = $relationship['parent'];
            $foreignKey = $relationship['foreign_key'];

            $integrity[$key.'_missing_parent'] = DB::table("{$child} as child")
                ->leftJoin("{$parent} as parent", 'parent.id', '=', "child.{$foreignKey}")
                ->whereNull('child.tenant_id')
                ->whereNotNull("child.{$foreignKey}")
                ->whereNull('parent.id')
                ->count();
            $integrity[$key.'_parent_owned_by_other_tenant'] = DB::table("{$child} as child")
                ->join("{$parent} as parent", 'parent.id', '=', "child.{$foreignKey}")
                ->whereNull('child.tenant_id')
                ->whereNotNull('parent.tenant_id')
                ->where('parent.tenant_id', '!=', $tenantId)
                ->count();
        }

        foreach ($this->membershipRelationships as $relationship) {
            $table = $relationship['table'];
            $userKey = $relationship['user_key'];
            $integrity[$table.'_'.$userKey.'_without_solarnet_membership'] = DB::table("{$table} as record")
                ->leftJoin('tenant_user as membership', function ($join) use ($tenantId, $userKey): void {
                    $join->on('membership.user_id', '=', "record.{$userKey}")
                        ->where('membership.tenant_id', '=', $tenantId)
                        ->where('membership.status', '=', 'active');
                })
                ->whereNull('record.tenant_id')
                ->whereNotNull("record.{$userKey}")
                ->whereNull('membership.id')
                ->count();
        }

        $integrity['settings_duplicate_tenant_keys'] = DB::table('settings as legacy')
            ->join('settings as owned', function ($join) use ($tenantId): void {
                $join->on('owned.key', '=', 'legacy.key')
                    ->where('owned.tenant_id', '=', $tenantId);
            })
            ->whereNull('legacy.tenant_id')
            ->count();

        return $integrity;
    }

    /** @return array<string, int|string> */
    private function invariantSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->tables as $table) {
            $snapshot[$table.'_count'] = DB::table($table)->count();
        }

        return [
            ...$snapshot,
            'invoices_count' => DB::table('invoices')->count(),
            'invoices_total' => $this->money('invoices', 'total'),
            'invoices_paid_amount' => $this->money('invoices', 'paid_amount'),
            'invoices_balance' => $this->money('invoices', 'balance'),
            'payments_count' => DB::table('payments')->count(),
            'payments_amount' => $this->money('payments', 'amount'),
            'remittances_count' => DB::table('remittances')->count(),
            'remittances_declared_amount' => $this->money('remittances', 'declared_amount'),
            'remittances_received_amount' => $this->money('remittances', 'received_amount'),
            'financial_entries_count' => DB::table('financial_entries')->count(),
            'financial_entries_amount' => $this->money('financial_entries', 'amount'),
            'daily_cash_counted_amount' => $this->money('daily_cash_counts', 'counted_amount'),
            'payroll_gross_pay' => $this->money('staff_payroll_disbursements', 'gross_pay'),
            'payroll_net_pay' => $this->money('staff_payroll_disbursements', 'net_pay'),
        ];
    }

    private function lockTables(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = array_values(array_unique([
            ...$this->tables,
            'customers', 'financial_entries', 'tenant_user',
        ]));
        DB::statement('LOCK TABLE '.implode(', ', $tables).' IN SHARE ROW EXCLUSIVE MODE');
    }

    private function money(string $table, string $column): string
    {
        return number_format((float) DB::table($table)->sum($column), 2, '.', '');
    }
}
