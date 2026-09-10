<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_confirmation_sms_status', 32)->nullable();
            $table->unsignedSmallInteger('payment_confirmation_sms_attempt_count')->default(0);
            $table->timestamp('payment_confirmation_sms_last_attempt_at')->nullable();
            $table->timestamp('payment_confirmation_sms_sent_at')->nullable();
            $table->string('payment_confirmation_sms_provider_id')->nullable();
            $table->text('payment_confirmation_sms_failure_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'payment_confirmation_sms_status', 'payment_confirmation_sms_attempt_count',
                'payment_confirmation_sms_last_attempt_at', 'payment_confirmation_sms_sent_at',
                'payment_confirmation_sms_provider_id', 'payment_confirmation_sms_failure_reason',
            ]);
        });
    }
};
