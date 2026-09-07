<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paymongo_outward_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_transfer_id')->unique();
            $table->string('webhook_event_id')->nullable()->unique();
            $table->string('reference_number')->nullable();
            $table->string('provider_reference_number')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_institution')->nullable();
            $table->string('recipient_account_last4', 4)->nullable();
            $table->string('rail', 32)->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('fee', 12, 2)->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->string('status', 32)->index();
            $table->text('failure_reason')->nullable();
            $table->uuid('financial_entry_id')->nullable()->unique();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();
            $table->foreign('financial_entry_id')->references('id')->on('financial_entries')->nullOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('paymongo_outward_transfers'); }
};
