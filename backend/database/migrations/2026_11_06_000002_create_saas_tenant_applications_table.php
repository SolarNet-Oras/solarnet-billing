<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_tenant_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('saas_subscription_plans')->restrictOnDelete();
            $table->string('company_name');
            $table->string('tenant_slug', 100)->index();
            $table->string('owner_name');
            $table->string('owner_email')->index();
            $table->string('owner_phone', 40);
            $table->string('password_hash');
            $table->text('business_address');
            $table->string('business_registration_number')->nullable();
            $table->string('status')->default('awaiting_email_verification')->index();
            $table->string('verification_token_hash', 64)->nullable()->unique();
            $table->timestampTz('verification_expires_at')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignUuid('approved_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_tenant_applications');
    }
};
