<?php

use App\Support\LegacyDefaultAdministrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Retire the old development-only administrator without deleting its audit
 * history. It becomes inactive, receives an unusable password, and has every
 * role revoked. Application guards also prevent this identity from signing in
 * or being recreated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $legacy = DB::table('users')
            ->whereRaw('LOWER(email) = ?', [LegacyDefaultAdministrator::EMAIL])
            ->first(['id']);

        if (!$legacy) {
            return;
        }

        DB::transaction(function () use ($legacy): void {
            if (Schema::hasTable('role_user')) {
                DB::table('role_user')->where('user_id', $legacy->id)->delete();
            }

            DB::table('users')->where('id', $legacy->id)->update([
                'is_active' => false,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'last_login_at' => null,
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: a development credential must not be restored.
    }
};
