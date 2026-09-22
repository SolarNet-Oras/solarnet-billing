<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_attendance_records', function (Blueprint $table): void {
            $table->string('overtime_status', 24)->default('not_applicable')->after('overtime_minutes')->index();
            $table->unsignedInteger('approved_overtime_minutes')->default(0)->after('overtime_status');
            $table->foreignUuid('overtime_reviewed_by')->nullable()->after('approved_overtime_minutes')->constrained('users')->nullOnDelete();
            $table->timestamp('overtime_reviewed_at')->nullable()->after('overtime_reviewed_by');
            $table->string('overtime_review_notes', 500)->nullable()->after('overtime_reviewed_at');
        });

        // Preserve historical payroll behavior. Only overtime captured after
        // this migration enters the new pending-approval workflow.
        DB::table('staff_attendance_records')->where('overtime_minutes', '>', 0)->update([
            'overtime_status' => 'approved',
            'approved_overtime_minutes' => DB::raw('overtime_minutes'),
        ]);
    }

    public function down(): void
    {
        Schema::table('staff_attendance_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('overtime_reviewed_by');
            $table->dropColumn(['overtime_status', 'approved_overtime_minutes', 'overtime_reviewed_at', 'overtime_review_notes']);
        });
    }
};
