<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_tenant_staff_access_requests', function (Blueprint $table): void {
            $table->string('requested_role', 50)->default('tenant_staff_admin')->after('phone')->index();
        });
    }

    public function down(): void
    {
        Schema::table('saas_tenant_staff_access_requests', function (Blueprint $table): void {
            $table->dropColumn('requested_role');
        });
    }
};
