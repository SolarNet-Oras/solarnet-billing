<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS invoices_customer_recurring_cycle_unique');
        DB::statement("CREATE UNIQUE INDEX invoices_customer_recurring_cycle_unique ON invoices (customer_id, recurring_cycle_date) WHERE recurring_cycle_date IS NOT NULL AND status <> 'cancelled'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invoices_customer_recurring_cycle_unique');
        DB::statement('CREATE UNIQUE INDEX invoices_customer_recurring_cycle_unique ON invoices (customer_id, recurring_cycle_date) WHERE recurring_cycle_date IS NOT NULL');
    }
};
