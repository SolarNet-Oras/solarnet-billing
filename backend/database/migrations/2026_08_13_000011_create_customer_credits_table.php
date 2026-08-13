<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('payment_id')->nullable();
            $table->decimal('original_amount', 12, 2);
            $table->decimal('remaining_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->index(['customer_id', 'remaining_amount']);
        });
    }

    public function down(): void { Schema::dropIfExists('customer_credits'); }
};
