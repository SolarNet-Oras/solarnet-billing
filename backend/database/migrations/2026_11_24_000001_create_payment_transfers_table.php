<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('payment_id');
            $table->uuid('source_invoice_id');
            $table->uuid('target_invoice_id');
            $table->uuid('source_customer_id');
            $table->uuid('target_customer_id');
            $table->decimal('amount', 12, 2);
            $table->decimal('retained_credit', 12, 2)->default(0);
            $table->string('reason');
            $table->string('idempotency_key')->unique();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->index(['source_invoice_id', 'target_invoice_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('payment_transfers'); }
};
