<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('address')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('status')->default('active')->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->boolean('subscription_exempt')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('membership_role')->default('legacy_staff');
            $table->string('status')->default('active')->index();
            $table->boolean('is_owner')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('saas_subscription_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('PHP');
            $table->string('billing_interval')->default('monthly');
            $table->unsignedSmallInteger('trial_days')->default(30);
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained('saas_subscription_plans')->nullOnDelete();
            $table->string('status')->default('trialing')->index();
            $table->timestampTz('trial_starts_at')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_starts_at')->nullable();
            $table->timestampTz('current_period_ends_at')->nullable();
            $table->timestampTz('next_payment_due_at')->nullable();
            $table->string('payment_status')->nullable();
            $table->timestampTz('last_successful_payment_at')->nullable();
            $table->string('payment_provider')->nullable();
            $table->string('payment_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('tenant_subscription_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('tenant_subscriptions')->nullOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained('saas_subscription_plans')->nullOnDelete();
            $table->string('provider');
            $table->string('provider_payment_id')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('idempotency_key')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('PHP');
            $table->string('status')->index();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_payment_id']);
        });

        Schema::create('tenant_subscription_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('tenant_subscriptions')->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->default('system');
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('context')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('tenant_support_access_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('granted_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('granted_to')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tenant_onboarding_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_fingerprint', 64)->index();
            $table->string('email_hash', 64)->nullable()->index();
            $table->string('phone_hash', 64)->nullable()->index();
            $table->string('ip_hash', 64)->nullable();
            $table->string('status')->index();
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_usage_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->json('metrics');
            $table->timestamps();
            $table->unique(['tenant_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage_snapshots');
        Schema::dropIfExists('tenant_onboarding_attempts');
        Schema::dropIfExists('tenant_support_access_grants');
        Schema::dropIfExists('tenant_subscription_events');
        Schema::dropIfExists('tenant_subscription_payments');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('saas_subscription_plans');
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }
};
