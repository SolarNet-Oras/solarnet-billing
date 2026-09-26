<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_referrals', function (Blueprint $table): void {
            $table->uuid('cash_paid_by')->nullable();
            $table->uuid('cash_financial_entry_id')->nullable()->unique();
            $table->timestamp('cash_paid_at')->nullable();
            $table->string('cash_payout_reference', 100)->nullable();
            $table->foreign('cash_paid_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cash_financial_entry_id')->references('id')->on('financial_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_referrals', function (Blueprint $table): void {
            $table->dropForeign(['cash_paid_by']);
            $table->dropForeign(['cash_financial_entry_id']);
            $table->dropColumn(['cash_paid_by', 'cash_financial_entry_id', 'cash_paid_at', 'cash_payout_reference']);
        });
    }
};
