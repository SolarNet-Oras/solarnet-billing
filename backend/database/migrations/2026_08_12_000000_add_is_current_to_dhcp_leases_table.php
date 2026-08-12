<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table) {
            $table->boolean('is_current')->default(true)->after('is_matched');
            $table->index(['router_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table) {
            $table->dropIndex(['router_id', 'is_current']);
            $table->dropColumn('is_current');
        });
    }
};
