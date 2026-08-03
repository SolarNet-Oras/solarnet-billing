<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The customer portal authenticates against customers, not users. Older
     * deployments granted the legacy customer user role staff permissions,
     * which exposed staff endpoints to public registrations.
     */
    public function up(): void
    {
        $role = Role::where('name', 'customer')->first();
        $role?->permissions()->detach();
    }

    public function down(): void
    {
        // Do not automatically restore staff permissions to this legacy role.
    }
};
