<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('router_threat_observations', function (Blueprint $table) {
            $table->timestamp('block_expires_at')->nullable();
            $table->index(['status', 'block_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('router_threat_observations', function (Blueprint $table) {
            $table->dropIndex(['status', 'block_expires_at']);
            $table->dropColumn('block_expires_at');
        });
    }
};
