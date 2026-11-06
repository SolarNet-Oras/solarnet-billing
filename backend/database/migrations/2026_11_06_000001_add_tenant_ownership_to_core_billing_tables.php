<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'service_plans',
        'customers',
        'invoices',
        'payments',
        'remittances',
        'financial_entries',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                // Nullable during the audited rollout. No existing record is
                // assigned or made inaccessible by this schema-only change.
                $table->foreignUuid('tenant_id')
                    ->nullable()
                    ->constrained('tenants')
                    ->restrictOnDelete();
                $table->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
