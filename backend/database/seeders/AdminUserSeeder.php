<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\LegacyDefaultAdministrator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates an explicitly configured bootstrap Super Administrator only.
 * No shared/demo account is ever created by a deployment.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('ADMIN_EMAIL', ''));
        $password = (string) env('ADMIN_PASSWORD', '');
        $name = trim((string) env('ADMIN_NAME', 'Initial Super Administrator'));
        $phone = trim((string) env('ADMIN_PHONE', ''));

        // Existing staff accounts are never changed when these settings are
        // absent. A fresh deployment must explicitly nominate its bootstrap
        // administrator rather than receiving a shared default credential.
        if ($email === '' || $password === '') {
            $this->command->warn('Skipped bootstrap administrator seed: set ADMIN_EMAIL and ADMIN_PASSWORD only for a new installation.');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || LegacyDefaultAdministrator::isReservedEmail($email)) {
            $this->command->warn('Skipped bootstrap administrator seed: choose a real, non-legacy administrator email address.');
            return;
        }

        if (mb_strlen($password) < 12) {
            $this->command->warn('Skipped bootstrap administrator seed: ADMIN_PASSWORD must be at least 12 characters.');
            return;
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name !== '' ? $name : 'Initial Super Administrator',
                'phone' => $phone !== '' ? $phone : null,
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $roleId = Role::where('name', 'super_admin')->value('id');
        if ($roleId && !$admin->roles()->where('roles.id', $roleId)->exists()) {
            $admin->roles()->attach($roleId);
        }

        $this->command->info("Bootstrap Super Administrator ready: {$email}");
    }
}
