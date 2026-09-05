<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $permissions = ['view-dhcp', 'view-tickets'];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'office_admin')->value('id');
        if (! $roleId) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('name', $this->permissions)->pluck('id') as $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'office_admin')->value('id');
        $permissionIds = DB::table('permissions')->whereIn('name', $this->permissions)->pluck('id');
        if ($roleId && $permissionIds->isNotEmpty()) {
            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }
    }
};
