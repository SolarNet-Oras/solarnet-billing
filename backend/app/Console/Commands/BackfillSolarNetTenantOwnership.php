<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BackfillSolarNetTenantOwnership extends Command
{
    private const CONFIRMATION = 'ASSIGN LEGACY BILLING RECORDS TO SOLARNET';

    protected $signature = 'saas:backfill-solarnet-ownership
                            {--execute : Assign currently unowned core billing records}
                            {--confirm= : Exact execution confirmation phrase}';

    protected $description = 'Audit or transactionally assign legacy core billing records to the primary SolarNet tenant';

    /** @var list<string> */
    private array $tables = [
        'service_plans',
        'customers',
        'invoices',
        'payments',
        'remittances',
        'financial_entries',
    ];

    public function handle(): int
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                $this->error("Missing {$table}.tenant_id; run the ownership migration first.");

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
            $this->error('Backfill stopped: ownership or relationship preconditions failed.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($tenant): array {
                $this->lockTables();

                $lockedPreview = $this->preview($tenant->id);
                if (! $lockedPreview['safe_for_execution']) {
                    throw new RuntimeException('Preconditions changed after table locks were acquired.');
                }

                $before = $this->financialSnapshot();
                $updated = [];
                foreach ($this->tables as $table) {
                    $updated[$table] = DB::table($table)
                        ->whereNull('tenant_id')
                        ->update(['tenant_id' => $tenant->id]);
                }

                $after = $this->financialSnapshot();
                if ($before !== $after) {
                    throw new RuntimeException('Financial invariants changed; transaction rolled back.');
                }

                $verification = $this->preview($tenant->id);
                if ($verification['unassigned_records'] !== 0
                    || $verification['records_assigned_to_other_tenants'] !== 0
                    || $verification['relationship_integrity_failures'] !== 0) {
                    throw new RuntimeException('Post-backfill ownership verification failed; transaction rolled back.');
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
            'financial_invariants_preserved' => $result['before'] === $result['after'],
            'verification' => $result['verification'],
            'action' => 'backfill_completed_and_verified',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function lockTables(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('LOCK TABLE '.implode(', ', $this->tables).' IN SHARE ROW EXCLUSIVE MODE');
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
                'assigned_to_other_tenant' => (clone $query)
                    ->whereNotNull('tenant_id')->where('tenant_id', '!=', $tenantId)->count(),
            ]];
        });
        $integrity = $this->integritySnapshot();
        $unassigned = $tables->sum('unassigned');
        $other = $tables->sum('assigned_to_other_tenant');
        $failures = array_sum($integrity);

        return [
            'tables' => $tables->all(),
            'integrity' => $integrity,
            'unassigned_records' => $unassigned,
            'records_assigned_to_other_tenants' => $other,
            'relationship_integrity_failures' => $failures,
            'safe_for_execution' => $other === 0 && $failures === 0,
        ];
    }

    /** @return array<string, int> */
    private function integritySnapshot(): array
    {
        return [
            'invoices_without_customer' => DB::table('invoices as i')
                ->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')->whereNull('c.id')->count(),
            'invoice_customer_tenant_mismatch' => DB::table('invoices as i')
                ->join('customers as c', 'c.id', '=', 'i.customer_id')
                ->whereNotNull('i.tenant_id')->whereNotNull('c.tenant_id')
                ->whereColumn('i.tenant_id', '!=', 'c.tenant_id')->count(),
            'payments_without_customer' => DB::table('payments as p')
                ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')->whereNull('c.id')->count(),
            'payment_customer_tenant_mismatch' => DB::table('payments as p')
                ->join('customers as c', 'c.id', '=', 'p.customer_id')
                ->whereNotNull('p.tenant_id')->whereNotNull('c.tenant_id')
                ->whereColumn('p.tenant_id', '!=', 'c.tenant_id')->count(),
            'payment_invoice_tenant_mismatch' => DB::table('payments as p')
                ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
                ->whereNotNull('p.tenant_id')->whereNotNull('i.tenant_id')
                ->whereColumn('p.tenant_id', '!=', 'i.tenant_id')->count(),
        ];
    }

    /** @return array<string, int|string> */
    private function financialSnapshot(): array
    {
        return [
            'service_plans_count' => DB::table('service_plans')->count(),
            'customers_count' => DB::table('customers')->count(),
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
        ];
    }

    private function money(string $table, string $column): string
    {
        return number_format((float) DB::table($table)->sum($column), 2, '.', '');
    }
}
