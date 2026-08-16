<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_web_push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            // A push endpoint is a browser-generated capability. It is kept
            // server-side and is never returned by the customer portal API.
            $table->text('endpoint')->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 32)->default('aes128gcm');
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->index(['customer_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_web_push_subscriptions');
    }
};
