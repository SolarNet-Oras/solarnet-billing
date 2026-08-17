<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_account_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->index();
            $table->uuid('payment_id')->nullable()->index();
            $table->uuid('invoice_id')->nullable()->index();
            $table->string('correlation_id')->nullable()->index();
            $table->string('action');
            $table->string('financial_status');
            $table->string('service_status');
            $table->string('previous_service_status')->nullable();
            $table->decimal('outstanding_balance', 12, 2)->default(0);
            $table->decimal('confirmed_payment_total', 12, 2)->default(0);
            $table->decimal('allocated_payment_total', 12, 2)->default(0);
            $table->decimal('available_credit_total', 12, 2)->default(0);
            $table->boolean('restoration_eligible')->default(false);
            $table->string('restoration_status')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_reconciliations');
    }
};
