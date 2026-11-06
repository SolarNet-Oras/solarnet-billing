<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditSolarNetTenantOwnership extends Command
{
    protected $signature = 'saas:audit-solarnet-ownership';

    protected $description = 'Read-only audit of nullable tenant ownership across core SolarNet billing records';

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
        $missingSchema = collect($this->tables)
            ->filter(fn (string $table): bool => ! Schema::hasColumn($table, 'tenant_id'))
            ->values();

        if ($missingSchema->isNotEmpty()) {
            $this->line(json_encode([
                'mode' => 'audit',
                'read_only' => true,
                'ready' => false,
                'missing_tenant_columns' => $missingSchema->all(),
                'action' => 'run_the_nullable_ownership_migration_first',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $tenant = Tenant::query()
            ->where('slug', config('saas.primary_tenant_slug'))
            ->where('is_primary', true)
            ->where('subscription_exempt', true)
            ->first();

        if (! $tenant) {
            $this->error('Primary subscription-exempt SolarNet tenant was not found; audit stopped.');

            return self::FAILURE;
        }

        $tableAudit = collect($this->tables)->mapWithKeys(function (string $table) use ($tenant): array {
            $query = DB::table($table);

            return [$table => [
                'total' => (clone $query)->count(),
                'unassigned' => (clone $query)->whereNull('tenant_id')->count(),
                'assigned_to_solarnet' => (clone $query)->where('tenant_id', $tenant->id)->count(),
                'assigned_to_other_tenant' => (clone $query)
                    ->whereNotNull('tenant_id')
                    ->where('tenant_id', '!=', $tenant->id)
                    ->count(),
            ]];
        });

        $integrity = [
            'invoices_without_customer' => DB::table('invoices as i')
                ->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')
                ->whereNull('c.id')->count(),
            'assigned_invoice_customer_tenant_mismatch' => DB::table('invoices as i')
                ->join('customers as c', 'c.id', '=', 'i.customer_id')
                ->whereNotNull('i.tenant_id')->whereNotNull('c.tenant_id')
                ->whereColumn('i.tenant_id', '!=', 'c.tenant_id')->count(),
            'payments_without_customer' => DB::table('payments as p')
                ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
                ->whereNull('c.id')->count(),
            'assigned_payment_customer_tenant_mismatch' => DB::table('payments as p')
                ->join('customers as c', 'c.id', '=', 'p.customer_id')
                ->whereNotNull('p.tenant_id')->whereNotNull('c.tenant_id')
                ->whereColumn('p.tenant_id', '!=', 'c.tenant_id')->count(),
            'assigned_payment_invoice_tenant_mismatch' => DB::table('payments as p')
                ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
                ->whereNotNull('p.tenant_id')->whereNotNull('i.tenant_id')
                ->whereColumn('p.tenant_id', '!=', 'i.tenant_id')->count(),
        ];

        $unassigned = $tableAudit->sum('unassigned');
        $otherTenant = $tableAudit->sum('assigned_to_other_tenant');
        $integrityFailures = array_sum($integrity);

        $this->line(json_encode([
            'mode' => 'audit',
            'read_only' => true,
            'ready' => true,
            'primary_tenant' => $tenant->only(['id', 'slug', 'name', 'is_primary', 'subscription_exempt']),
            'tables' => $tableAudit->all(),
            'integrity' => $integrity,
            'summary' => [
                'unassigned_records' => $unassigned,
                'records_assigned_to_other_tenants' => $otherTenant,
                'relationship_integrity_failures' => $integrityFailures,
                'safe_for_backfill_execution' => $otherTenant === 0 && $integrityFailures === 0,
            ],
            'action' => 'no_changes_audit_only',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
