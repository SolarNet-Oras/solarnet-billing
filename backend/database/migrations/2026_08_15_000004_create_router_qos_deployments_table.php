<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('router_qos_deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id');
            $table->unsignedInteger('configuration_version');
            $table->string('status', 24);
            $table->string('strategy', 64)->nullable();
            $table->string('queue_type', 128)->nullable();
            $table->json('configuration')->nullable();
            $table->json('inspection')->nullable();
            $table->string('backup_filename')->nullable();
            $table->timestamp('backup_verified_at')->nullable();
            $table->json('verification')->nullable();
            $table->text('failure_reason')->nullable();
            $table->uuid('created_by');
            $table->uuid('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->uuid('rolled_back_by')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('routers')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('applied_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rolled_back_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['router_id', 'configuration_version'], 'router_qos_version_unique');
            $table->index(['router_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_qos_deployments');
    }
};
