<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sms_advisory_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('created_by');
            $table->string('title', 120);
            $table->text('message');
            $table->string('recipient_filter', 32);
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('sms_advisory_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->uuid('customer_id')->nullable();
            $table->string('recipient', 20);
            $table->string('recipient_last4', 4);
            $table->string('status', 24)->default('queued')->index();
            $table->string('provider_message_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->foreign('campaign_id')->references('id')->on('sms_advisory_campaigns')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->unique(['campaign_id', 'recipient']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_advisory_recipients');
        Schema::dropIfExists('sms_advisory_campaigns');
    }
};
