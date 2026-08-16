<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_web_push_subscriptions', function (Blueprint $table) {
            // A locally generated browser device identifier is optional. It is
            // only used for the customer’s own device list; the endpoint is
            // still the delivery credential.
            $table->uuid('device_id')->nullable()->after('customer_id');
            $table->string('platform', 80)->nullable()->after('content_encoding');
            $table->string('browser', 80)->nullable()->after('platform');
            $table->timestamp('revoked_at')->nullable()->after('failure_reason');
            $table->index(['customer_id', 'revoked_at'], 'customer_push_subscription_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_web_push_subscriptions', function (Blueprint $table) {
            $table->dropIndex('customer_push_subscription_active_index');
            $table->dropColumn(['device_id', 'platform', 'browser', 'revoked_at']);
        });
    }
};
