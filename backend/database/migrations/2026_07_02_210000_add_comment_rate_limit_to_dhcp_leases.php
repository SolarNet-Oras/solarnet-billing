<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table) {
            // MikroTik lease comment (used as customer name for static leases)
            $table->string('comment')->nullable()->after('hostname');
            // MikroTik rate-limit string, e.g. "10M/5M" (used as subscription hint)
            $table->string('rate_limit')->nullable()->after('comment');
            // True when MikroTik reports the lease as "dynamic"
            $table->boolean('is_dynamic')->default(true)->after('rate_limit');

            $table->index('is_dynamic');
        });
    }

    public function down(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table) {
            $table->dropIndex(['is_dynamic']);
            $table->dropColumn(['comment', 'rate_limit', 'is_dynamic']);
        });
    }
};
