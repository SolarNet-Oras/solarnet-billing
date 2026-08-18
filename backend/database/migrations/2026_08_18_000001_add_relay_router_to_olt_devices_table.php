<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OLT management addresses are private in most SolarNet sites.  Bind an
     * OLT explicitly to the MikroTik that can reach it so the application can
     * relay read-only SNMP through its existing authenticated API connection.
     */
    public function up(): void
    {
        Schema::table('olt_devices', function (Blueprint $table) {
            $table->foreignUuid('router_id')->nullable()->constrained('routers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('olt_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('router_id');
        });
    }
};
