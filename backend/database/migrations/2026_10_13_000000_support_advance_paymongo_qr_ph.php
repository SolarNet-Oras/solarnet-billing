<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('paymongo_checkouts', function (Blueprint $table): void {
            $table->dropForeign(['invoice_id']);
            $table->uuid('invoice_id')->nullable()->change();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->date('advance_payment_date')->nullable()->after('amount');
            $table->date('covered_cycle_date')->nullable()->after('advance_payment_date');
            $table->uuid('initiated_by')->nullable()->index()->after('covered_cycle_date');
            $table->foreign('initiated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paymongo_checkouts', function (Blueprint $table): void {
            $table->dropForeign(['initiated_by']);
            $table->dropIndex(['initiated_by']);
            $table->dropColumn(['advance_payment_date', 'covered_cycle_date', 'initiated_by']);
            $table->dropForeign(['invoice_id']);
        });

        // Advance QR attempts cannot exist in the old non-null invoice schema.
        \Illuminate\Support\Facades\DB::table('paymongo_checkouts')->whereNull('invoice_id')->delete();

        Schema::table('paymongo_checkouts', function (Blueprint $table): void {
            $table->uuid('invoice_id')->nullable(false)->change();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
        });
    }
};
