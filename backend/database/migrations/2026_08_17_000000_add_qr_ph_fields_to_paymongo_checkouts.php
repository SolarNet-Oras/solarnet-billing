<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('paymongo_checkouts', function (Blueprint $table) {
            $table->string('checkout_type', 30)->default('hosted_checkout')->index()->after('checkout_session_id');
            $table->string('payment_intent_id')->nullable()->unique()->after('checkout_type');
            $table->string('payment_method_id')->nullable()->index()->after('payment_intent_id');
            $table->string('paymongo_payment_id')->nullable()->unique()->after('payment_method_id');
            $table->text('payment_intent_client_key')->nullable()->after('paymongo_payment_id');
            $table->text('qr_image_url')->nullable()->after('payment_intent_client_key');
            $table->string('webhook_event_id')->nullable()->unique()->after('qr_image_url');
            $table->timestamp('expires_at')->nullable()->index()->after('webhook_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('paymongo_checkouts', function (Blueprint $table) {
            $table->dropUnique(['payment_intent_id']);
            $table->dropUnique(['paymongo_payment_id']);
            $table->dropUnique(['webhook_event_id']);
            $table->dropIndex(['checkout_type']);
            $table->dropIndex(['payment_method_id']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn([
                'checkout_type', 'payment_intent_id', 'payment_method_id', 'paymongo_payment_id',
                'payment_intent_client_key', 'qr_image_url', 'webhook_event_id', 'expires_at',
            ]);
        });
    }
};
