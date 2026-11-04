<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collector_customer_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('collector_id')->index();
            $table->uuid('customer_id')->unique();
            $table->uuid('assigned_by')->nullable();
            $table->string('territory_label')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable()->index();
            $table->timestamps();
            $table->foreign('collector_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('customer_collection_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->unique();
            $table->boolean('visit_allowed')->default(true);
            $table->boolean('early_collection_allowed')->default(false);
            $table->time('preferred_start_time')->nullable();
            $table->time('preferred_end_time')->nullable();
            $table->string('address_group_override')->nullable();
            $table->text('payment_arrangement')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('collection_planner_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('geofence_meters')->default(100);
            $table->time('shift_start')->default('08:00');
            $table->time('shift_end')->default('17:00');
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->unsignedInteger('estimated_visit_minutes')->default(20);
            $table->unsignedInteger('critical_weight')->default(400);
            $table->unsignedInteger('due_today_weight')->default(300);
            $table->unsignedInteger('upcoming_three_day_weight')->default(200);
            $table->unsignedInteger('upcoming_seven_day_weight')->default(100);
            $table->unsignedInteger('repeat_attempt_penalty')->default(25);
            $table->unsignedInteger('verified_gps_bonus')->default(10);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('collection_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('collector_id')->index();
            $table->date('plan_date')->index();
            $table->string('status')->default('active')->index();
            $table->boolean('smart_enabled')->default(false);
            $table->decimal('start_latitude', 10, 7)->nullable();
            $table->decimal('start_longitude', 10, 7)->nullable();
            $table->string('start_address')->nullable();
            $table->string('route_mode')->default('urgency_address');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->foreign('collector_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['collector_id', 'plan_date']);
        });

        Schema::create('collection_plan_stops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('collection_plan_id')->index();
            $table->uuid('customer_id')->index();
            $table->unsignedInteger('sequence');
            $table->string('priority_level');
            $table->integer('priority_score');
            $table->string('address_group')->nullable()->index();
            $table->string('location_source')->default('address_only');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('straight_line_km', 10, 3)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->decimal('collectible_balance', 12, 2);
            $table->date('earliest_due_date');
            $table->string('status')->default('planned')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('skip_reason')->nullable();
            $table->timestamps();
            $table->foreign('collection_plan_id')->references('id')->on('collection_plans')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->unique(['collection_plan_id', 'customer_id']);
        });

        Schema::create('collection_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('collection_plan_stop_id')->index();
            $table->uuid('collector_id')->index();
            $table->uuid('customer_id')->index();
            $table->string('status')->default('started')->index();
            $table->string('outcome')->nullable()->index();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 10, 2)->nullable();
            $table->decimal('distance_from_customer_meters', 12, 2)->nullable();
            $table->boolean('gps_verified')->default(false);
            $table->boolean('requires_review')->default(false)->index();
            $table->string('location_exception_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->string('photo_path')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();
            $table->foreign('collection_plan_stop_id')->references('id')->on('collection_plan_stops')->cascadeOnDelete();
            $table->foreign('collector_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
        });

        Schema::create('collection_visit_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('collection_visit_id')->index();
            $table->uuid('invoice_id')->index();
            $table->decimal('balance_at_visit', 12, 2);
            $table->timestamps();
            $table->foreign('collection_visit_id')->references('id')->on('collection_visits')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $table->unique(['collection_visit_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_visit_invoices');
        Schema::dropIfExists('collection_visits');
        Schema::dropIfExists('collection_plan_stops');
        Schema::dropIfExists('collection_plans');
        Schema::dropIfExists('collection_planner_settings');
        Schema::dropIfExists('customer_collection_preferences');
        Schema::dropIfExists('collector_customer_assignments');
    }
};
