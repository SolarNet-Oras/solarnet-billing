<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_flow_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('source_type', 120);
            $table->uuid('source_id');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 32)->nullable();
            $table->string('effect_type', 32)->nullable();
            $table->string('source_wallet', 32)->nullable();
            $table->string('destination_wallet', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
            $table->index(['payment_method', 'effect_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_flow_logs');
    }
};
