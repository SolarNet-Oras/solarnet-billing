<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignUuid('tenant_id')
                    ->nullable()
                    ->constrained('tenants')
                    ->restrictOnDelete();
                $blueprint->index('tenant_id');
            });
        }

        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'tenant_id')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->dropUnique(['key']);
                $table->unique(['tenant_id', 'key']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'tenant_id')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->dropUnique(['tenant_id', 'key']);
                $table->unique('key');
            });
        }

        foreach (array_reverse($this->tables) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['tenant_id']);
                $blueprint->dropIndex(['tenant_id']);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};
