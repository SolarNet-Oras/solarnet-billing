<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('staff_payroll_disbursements')) return;
        if (Schema::hasColumn('staff_payroll_disbursements', 'processed_at') && ! Schema::hasColumn('staff_payroll_disbursements', 'prepared_at')) {
            Schema::table('staff_payroll_disbursements', fn (Blueprint $table) => $table->renameColumn('processed_at', 'prepared_at'));
        }
        if (! Schema::hasColumn('staff_payroll_disbursements', 'released_at')) {
            Schema::table('staff_payroll_disbursements', fn (Blueprint $table) => $table->timestamp('released_at')->nullable()->after('prepared_at'));
        }
        DB::table('staff_payroll_disbursements')->where('status', 'processed')->update(['status'=>'scheduled']);
        DB::statement("ALTER TABLE staff_payroll_disbursements ALTER COLUMN status SET DEFAULT 'scheduled'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('staff_payroll_disbursements')) return;
        DB::statement("ALTER TABLE staff_payroll_disbursements ALTER COLUMN status SET DEFAULT 'processed'");
        DB::table('staff_payroll_disbursements')->where('status', 'scheduled')->update(['status'=>'processed']);
        if (Schema::hasColumn('staff_payroll_disbursements', 'released_at')) {
            Schema::table('staff_payroll_disbursements', fn (Blueprint $table) => $table->dropColumn('released_at'));
        }
        if (Schema::hasColumn('staff_payroll_disbursements', 'prepared_at') && ! Schema::hasColumn('staff_payroll_disbursements', 'processed_at')) {
            Schema::table('staff_payroll_disbursements', fn (Blueprint $table) => $table->renameColumn('prepared_at', 'processed_at'));
        }
    }
};
