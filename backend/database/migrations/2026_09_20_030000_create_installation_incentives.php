<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('installation_incentive_pools', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_id')->unique()->constrained('tickets')->cascadeOnDelete();
            $table->date('work_date')->index();
            $table->decimal('pool_amount', 12, 2)->default(500);
            $table->unsignedSmallInteger('eligible_count')->default(0);
            $table->string('status', 32)->default('allocated')->index();
            $table->timestamps();
        });
        Schema::create('installation_incentive_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pool_id')->constrained('installation_incentive_pools')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('attendance_record_id')->nullable()->constrained('staff_attendance_records')->nullOnDelete();
            $table->foreignUuid('payroll_disbursement_id')->nullable()->constrained('staff_payroll_disbursements')->nullOnDelete();
            $table->date('work_date')->index();
            $table->decimal('share_amount', 12, 2);
            $table->string('status', 24)->default('earned')->index();
            $table->timestamps();
            $table->unique(['pool_id', 'user_id']);
        });
        Schema::table('staff_payroll_disbursements', function (Blueprint $table): void {
            $table->decimal('installation_incentive', 12, 2)->default(0)->after('allowance');
        });
    }

    public function down(): void
    {
        Schema::table('staff_payroll_disbursements', fn (Blueprint $table) => $table->dropColumn('installation_incentive'));
        Schema::dropIfExists('installation_incentive_allocations');
        Schema::dropIfExists('installation_incentive_pools');
    }
};
