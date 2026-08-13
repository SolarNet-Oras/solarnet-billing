<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * One-time operator-requested portal reset. This deliberately replaces all
 * earlier customer portal passwords with the temporary onboarding password so
 * legacy and newly-created accounts can use one documented first login.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('customers')->update([
            'portal_password' => Hash::make(\App\Services\CustomerAccountService::TEMPORARY_PORTAL_PASSWORD),
            'portal_password_set_at' => now(),
            'portal_password_change_required' => true,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Password hashes cannot safely be restored after an intentional reset.
    }
};
