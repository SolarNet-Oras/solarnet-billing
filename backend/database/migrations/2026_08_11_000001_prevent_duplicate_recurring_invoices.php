<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('recurring_cycle_date')->nullable()->after('billing_period_end');
            $table->index('recurring_cycle_date');
        });

        // Multiple one-off invoices remain allowed. Only invoices marked as a
        // recurring billing cycle are unique per customer and cycle date.
        DB::statement('CREATE UNIQUE INDEX invoices_customer_recurring_cycle_unique ON invoices (customer_id, recurring_cycle_date) WHERE recurring_cycle_date IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invoices_customer_recurring_cycle_unique');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['recurring_cycle_date']);
            $table->dropColumn('recurring_cycle_date');
        });
    }
};
