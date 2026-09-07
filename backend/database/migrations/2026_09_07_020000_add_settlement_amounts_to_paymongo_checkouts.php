<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('paymongo_checkouts', function (Blueprint $table): void {
            $table->decimal('provider_gross_amount', 12, 2)->nullable();
            $table->decimal('provider_fee', 12, 2)->nullable();
            $table->decimal('provider_net_amount', 12, 2)->nullable();
            $table->string('provider_payment_method', 64)->nullable();
            $table->string('provider_balance_transaction_id')->nullable();
            $table->string('settlement_status', 32)->default('pending');
            $table->timestamp('settlement_captured_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('paymongo_checkouts', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_gross_amount', 'provider_fee', 'provider_net_amount',
                'provider_payment_method', 'provider_balance_transaction_id',
                'settlement_status', 'settlement_captured_at',
            ]);
        });
    }
};
