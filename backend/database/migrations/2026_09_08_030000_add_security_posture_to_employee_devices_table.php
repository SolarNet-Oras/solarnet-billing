<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_devices', function (Blueprint $table) {
            $table->json('security_posture')->nullable();
            $table->timestamp('posture_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_devices', function (Blueprint $table) {
            $table->dropColumn(['security_posture', 'posture_checked_at']);
        });
    }
};
