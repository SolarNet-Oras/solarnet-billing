<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'office_admin')->value('id');
        $permissionId = DB::table('permissions')->where('name', 'edit-customers')->value('id');

        if ($roleId && $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'office_admin')->value('id');
        $permissionId = DB::table('permissions')->where('name', 'edit-customers')->value('id');

        if ($roleId && $permissionId) {
            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
        }
    }
};
