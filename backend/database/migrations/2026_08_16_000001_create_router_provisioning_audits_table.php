<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_provisioning_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id')->index();
            $table->string('status', 40)->index();
            $table->json('discovery');
            $table->json('plan')->nullable();
            $table->string('backup_filename')->nullable();
            $table->json('verification')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignUuid('discovered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_provisioning_audits');
    }
};
