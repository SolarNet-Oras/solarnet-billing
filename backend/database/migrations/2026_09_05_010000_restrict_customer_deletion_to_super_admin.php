<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'delete-customers')->value('id');
        if (! $permissionId) {
            return;
        }

        $nonSuperAdminRoleIds = DB::table('roles')->where('name', '!=', 'super_admin')->pluck('id');
        DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $nonSuperAdminRoleIds)
            ->delete();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'delete-customers')->value('id');
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($permissionId && $adminRoleId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $adminRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
