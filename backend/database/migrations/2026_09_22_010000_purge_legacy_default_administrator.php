<?php

use App\Support\LegacyDefaultAdministrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanently remove the retired development-only administrator identity.
 * Login, signup, password reset, user listings, and seeding already reserve
 * this email; this migration removes the remaining inert database row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $legacy = DB::table('users')
            ->whereRaw('LOWER(email) = ?', [LegacyDefaultAdministrator::EMAIL])
            ->first(['id', 'email']);

        if (! $legacy) {
            return;
        }

        DB::transaction(function () use ($legacy): void {
            if (Schema::hasTable('role_user')) {
                DB::table('role_user')->where('user_id', $legacy->id)->delete();
            }
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $legacy->id)->delete();
            }
            if (Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')
                    ->whereRaw('LOWER(email) = ?', [LegacyDefaultAdministrator::EMAIL])
                    ->delete();
            }

            DB::table('users')->where('id', $legacy->id)->delete();
        });
    }

    public function down(): void
    {
        // Intentionally irreversible. This development identity must not return.
    }
};
