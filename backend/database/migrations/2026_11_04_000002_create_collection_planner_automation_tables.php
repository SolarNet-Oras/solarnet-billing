<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collector_territories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('collector_id')->index();
            $table->string('name');
            $table->string('address_pattern')->nullable();
            $table->boolean('is_fallback')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('priority')->default(100);
            $table->unsignedInteger('max_daily_visits')->default(24);
            $table->decimal('start_latitude', 10, 7)->nullable();
            $table->decimal('start_longitude', 10, 7)->nullable();
            $table->string('start_address')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->foreign('collector_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['collector_id','name']);
        });

        Schema::create('collection_planner_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('run_date')->index();
            $table->string('idempotency_key')->unique();
            $table->string('trigger')->default('schedule');
            $table->string('status')->default('running')->index();
            $table->string('algorithm_version');
            $table->boolean('dry_run')->default(false);
            $table->unsignedInteger('eligible_customers')->default(0);
            $table->unsignedInteger('new_assignments')->default(0);
            $table->unsignedInteger('unassigned_exceptions')->default(0);
            $table->unsignedInteger('collectors_processed')->default(0);
            $table->unsignedInteger('plans_generated')->default(0);
            $table->unsignedInteger('scheduled_visits')->default(0);
            $table->unsignedInteger('unscheduled_visits')->default(0);
            $table->json('summary')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('collection_plans', function (Blueprint $table) {
            $table->uuid('collection_planner_run_id')->nullable()->after('collector_id')->index();
            $table->string('generation_mode')->default('manual')->after('status');
            $table->string('algorithm_version')->nullable()->after('route_mode');
            $table->foreign('collection_planner_run_id')->references('id')->on('collection_planner_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collection_plans', function (Blueprint $table) {
            $table->dropForeign(['collection_planner_run_id']);
            $table->dropColumn(['collection_planner_run_id','generation_mode','algorithm_version']);
        });
        Schema::dropIfExists('collection_planner_runs');
        Schema::dropIfExists('collector_territories');
    }
};
