<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_payroll_disbursements', function (Blueprint $table): void {
            $table->string('release_method', 24)->nullable()->after('released_at');
            $table->string('release_reference', 100)->nullable()->after('release_method');
            $table->foreignUuid('released_by')->nullable()->after('release_reference')->constrained('users')->nullOnDelete();
            $table->foreignUuid('financial_entry_id')->nullable()->unique()->after('released_by')->constrained('financial_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff_payroll_disbursements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('financial_entry_id');
            $table->dropConstrainedForeignId('released_by');
            $table->dropColumn(['release_method', 'release_reference']);
        });
    }
};
