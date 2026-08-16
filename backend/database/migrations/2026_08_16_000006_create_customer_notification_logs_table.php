<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('invoice_id')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->uuid('subscription_id')->nullable();
            // This is a hash of customer + billing event + scheduled date +
            // subscription. Its unique constraint prevents duplicate alerts
            // when a scheduled command is retried or run manually.
            $table->string('dispatch_key', 64)->unique();
            $table->string('notification_type', 80);
            $table->string('title', 255);
            $table->string('route', 512);
            $table->string('status', 32)->default('queued');
            $table->string('provider_message_id', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            // Browser Web Push cannot reliably confirm user-visible delivery;
            // delivered_at remains null unless a provider supplies proof.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->foreign('subscription_id')->references('id')->on('customer_web_push_subscriptions')->nullOnDelete();
            $table->index(['customer_id', 'created_at']);
            $table->index(['notification_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notification_logs');
    }
};
