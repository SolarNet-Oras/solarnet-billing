<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radius_subscribers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->unique();
            $table->uuid('router_id')->nullable()->index();
            // RouterOS DHCP RADIUS identifies a subscriber by MAC address.
            $table->string('radius_username', 64)->nullable()->unique();
            // Keep the reported MAC for administrator conflict review. Only
            // radius_username is unique, because conflicting records must be
            // visible and explicitly resolved rather than failing silently.
            $table->string('mac_address', 17)->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('authorization_status', 32)->index();
            $table->string('billing_status', 32)->nullable()->index();
            $table->string('rate_limit', 128)->nullable();
            $table->string('restricted_rate_limit', 128)->nullable();
            $table->boolean('requires_captive_portal')->default(false)->index();
            $table->boolean('mac_conflict')->default(false)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_authenticated_at')->nullable();
            $table->timestamp('last_accounting_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
        });

        Schema::create('radius_authorization_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('radius_subscriber_id')->nullable()->index();
            $table->uuid('customer_id')->nullable()->index();
            $table->uuid('router_id')->nullable()->index();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('event', 64)->index();
            $table->string('decision', 32)->nullable()->index();
            $table->text('reason')->nullable();
            $table->string('source', 64)->default('billing');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('radius_subscriber_id')->references('id')->on('radius_subscribers')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('radius_accounting_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('radius_subscriber_id')->nullable()->index();
            $table->uuid('customer_id')->nullable()->index();
            $table->uuid('router_id')->nullable()->index();
            $table->string('session_id', 128);
            $table->string('radius_username', 64)->nullable()->index();
            $table->string('mac_address', 17)->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('nas_identifier', 128)->nullable()->index();
            $table->timestamp('session_started_at')->nullable()->index();
            $table->timestamp('last_interim_at')->nullable()->index();
            $table->timestamp('session_stopped_at')->nullable()->index();
            $table->unsignedBigInteger('session_duration_seconds')->nullable();
            $table->unsignedBigInteger('input_octets')->nullable();
            $table->unsignedBigInteger('output_octets')->nullable();
            $table->string('termination_cause', 128)->nullable();
            $table->string('status', 32)->default('started')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'session_id']);
            $table->foreign('radius_subscriber_id')->references('id')->on('radius_subscribers')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_accounting_sessions');
        Schema::dropIfExists('radius_authorization_logs');
        Schema::dropIfExists('radius_subscribers');
    }
};
