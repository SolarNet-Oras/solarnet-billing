<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_compensations', function (Blueprint $table): void {
            $table->string('job_title')->nullable();
            $table->string('employment_status', 30)->default('regular');
            $table->date('hire_date')->nullable();
            $table->text('employee_address')->nullable();
            $table->decimal('sss_deduction', 12, 2)->default(0);
            $table->decimal('philhealth_deduction', 12, 2)->default(0);
            $table->decimal('pagibig_deduction', 12, 2)->default(0);
            $table->decimal('cash_advance_deduction', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('staff_compensations', function (Blueprint $table): void {
            $table->dropColumn(['job_title', 'employment_status', 'hire_date', 'employee_address', 'sss_deduction', 'philhealth_deduction', 'pagibig_deduction', 'cash_advance_deduction']);
        });
    }
};
