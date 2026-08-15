<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_credits', function (Blueprint $table) {
            // Null remains the legacy/generic credit behaviour: apply to the
            // next invoice. A non-null date reserves a payment for that exact
            // recurring due-date cycle and must never pay an older invoice.
            $table->date('covered_cycle_date')->nullable()->after('payment_id');
            $table->date('covered_period_start')->nullable()->after('covered_cycle_date');
            $table->date('covered_period_end')->nullable()->after('covered_period_start');
            $table->string('status', 32)->default('unallocated')->after('remaining_amount');
            $table->timestamp('applied_at')->nullable()->after('status');
            $table->index(['customer_id', 'covered_cycle_date']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_credits', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'covered_cycle_date']);
            $table->dropColumn([
                'covered_cycle_date',
                'covered_period_start',
                'covered_period_end',
                'status',
                'applied_at',
            ]);
        });
    }
};
