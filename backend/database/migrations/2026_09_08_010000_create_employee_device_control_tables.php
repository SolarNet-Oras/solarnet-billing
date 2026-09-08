<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_device_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code_hash', 64)->unique();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrollment_id')->nullable()->constrained('employee_device_enrollments')->nullOnDelete();
            $table->string('name', 120);
            $table->string('employee_name', 120);
            $table->string('platform', 20);
            $table->string('os_version', 120)->nullable();
            $table->string('agent_version', 40);
            $table->string('device_fingerprint_hash', 64)->unique();
            $table->string('token_hash', 64)->unique();
            $table->boolean('consent_accepted')->default(false);
            $table->timestamp('consent_accepted_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip_hash', 64)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('employee_device_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->nullable()->constrained('employee_devices')->nullOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 80);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_device_audits');
        Schema::dropIfExists('employee_devices');
        Schema::dropIfExists('employee_device_enrollments');
    }
};
