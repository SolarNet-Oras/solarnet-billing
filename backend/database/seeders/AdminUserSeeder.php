<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the initial Super Administrator only.
 * No demo/sample accounts are created.
 * Uses ADMIN_EMAIL / ADMIN_PASSWORD env vars in production (see .env).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('ADMIN_EMAIL', 'admin@ispbilling.local');
        $password = env('ADMIN_PASSWORD', 'password');
        $name     = env('ADMIN_NAME', 'Super Administrator');
        $phone    = env('ADMIN_PHONE', '+000000000');

        // Idempotent: create-or-update
        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name'              => $name,
                'phone'             => $phone,
                'password'          => Hash::make($password),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );

        // Assign super_admin role (only if not already assigned)
        $roleId = Role::where('name', 'super_admin')->value('id');
        if ($roleId && !$admin->roles()->where('roles.id', $roleId)->exists()) {
            $admin->roles()->attach($roleId);
        }

        $this->command->info("Super Administrator ready: {$email}");
        if ($password === 'password') {
            $this->command->warn('  ⚠  Default password is in use. Change it in the app immediately, or set ADMIN_PASSWORD in .env before seeding.');
        }
    }
}
