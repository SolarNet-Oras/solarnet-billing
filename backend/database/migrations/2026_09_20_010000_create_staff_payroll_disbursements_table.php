<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_payroll_disbursements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('cutoff_start');
            $table->date('cutoff_end');
            $table->date('pay_date');
            $table->decimal('base_pay', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('allowance', 12, 2)->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);
            $table->decimal('late_deduction', 12, 2)->default(0);
            $table->decimal('sss_deduction', 12, 2)->default(0);
            $table->decimal('philhealth_deduction', 12, 2)->default(0);
            $table->decimal('pagibig_deduction', 12, 2)->default(0);
            $table->decimal('cash_advance_deduction', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->unsignedSmallInteger('present_days')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('status', 24)->default('processed')->index();
            $table->timestamp('processed_at');
            $table->timestamp('payslip_emailed_at')->nullable();
            $table->text('email_error')->nullable();
            $table->json('calculation_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'pay_date']);
            $table->index(['pay_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_payroll_disbursements');
    }
};
