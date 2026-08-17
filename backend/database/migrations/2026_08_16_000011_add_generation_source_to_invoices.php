<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('generation_source', 32)
                ->default('manual')
                ->after('recurring_cycle_date');
        });

        // Preserve the notification behavior of historical scheduler invoices.
        DB::table('invoices')
            ->whereNotNull('recurring_cycle_date')
            ->update(['generation_source' => 'recurring']);

        // Migration opening balances are one-time records, never recurring
        // reminder candidates.
        DB::table('invoices')
            ->where('notes', 'like', 'Migrated opening balance%')
            ->update(['generation_source' => 'migration']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('generation_source');
        });
    }
};
