<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_sms_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('invoice_id');
            $table->string('notification_type', 80);
            $table->string('phone_number', 20);
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->string('portal_url', 512);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('status', 32)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->unique(['customer_id', 'invoice_id', 'notification_type'], 'billing_sms_notification_once_per_invoice');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_sms_notifications');
    }
};
