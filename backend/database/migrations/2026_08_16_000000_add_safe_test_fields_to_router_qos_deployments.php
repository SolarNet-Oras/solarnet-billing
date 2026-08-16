<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('router_qos_deployments', function (Blueprint $table) {
            $table->timestamp('test_started_at')->nullable()->after('applied_at');
            $table->timestamp('test_expires_at')->nullable()->after('test_started_at');
            $table->timestamp('test_completed_at')->nullable()->after('test_expires_at');
            $table->index(['status', 'test_expires_at'], 'router_qos_test_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('router_qos_deployments', function (Blueprint $table) {
            $table->dropIndex('router_qos_test_expiry_index');
            $table->dropColumn(['test_started_at', 'test_expires_at', 'test_completed_at']);
        });
    }
};
