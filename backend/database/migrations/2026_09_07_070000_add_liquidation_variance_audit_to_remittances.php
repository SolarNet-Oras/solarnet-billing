<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('remittances', function (Blueprint $table): void {
            $table->decimal('liquidation_variance', 12, 2)->default(0);
            $table->string('shortage_reason')->nullable();
            $table->string('expense_receipt_reference')->nullable();
            $table->string('expense_receipt_path')->nullable();
            $table->uuid('variance_financial_entry_id')->nullable();
            $table->foreign('variance_financial_entry_id')->references('id')->on('financial_entries')->nullOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('remittances', function (Blueprint $table): void {
            $table->dropForeign(['variance_financial_entry_id']);
            $table->dropColumn(['liquidation_variance', 'shortage_reason', 'expense_receipt_reference', 'expense_receipt_path', 'variance_financial_entry_id']);
        });
    }
};
