<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the legacy `demo@ispbilling.local` account that was seeded in early
 * development. The app now runs in real-data mode with a single Super Admin.
 * Safe to run on databases that never had the demo user.
 */
return new class extends Migration {
    public function up(): void
    {
        $demoEmails = ['demo@ispbilling.local'];

        $userIds = DB::table('users')->whereIn('email', $demoEmails)->pluck('id');

        if ($userIds->isNotEmpty()) {
            // Detach any pivot rows first (users have role_user pivot)
            if (DB::getSchemaBuilder()->hasTable('role_user')) {
                DB::table('role_user')->whereIn('user_id', $userIds)->delete();
            }
            DB::table('users')->whereIn('id', $userIds)->delete();
        }
    }

    public function down(): void
    {
        // Not reversible — we don't recreate demo accounts.
    }
};
