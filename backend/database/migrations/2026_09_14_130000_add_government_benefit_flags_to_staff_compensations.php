<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_compensations', function (Blueprint $table): void {
            $table->boolean('sss_enabled')->default(false);
            $table->boolean('philhealth_enabled')->default(false);
            $table->boolean('pagibig_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('staff_compensations', function (Blueprint $table): void {
            $table->dropColumn(['sss_enabled', 'philhealth_enabled', 'pagibig_enabled']);
        });
    }
};
