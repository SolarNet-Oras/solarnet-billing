<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_id');
            $table->uuid('financial_entry_id')->nullable();
            $table->uuid('refunded_by')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 32);
            $table->string('reason', 500);
            $table->timestamp('refunded_at');
            $table->timestamps();

            $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
            $table->foreign('financial_entry_id')->references('id')->on('financial_entries')->nullOnDelete();
            $table->foreign('refunded_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['payment_id', 'refunded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
