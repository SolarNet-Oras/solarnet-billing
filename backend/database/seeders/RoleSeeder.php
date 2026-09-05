<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define roles
        $roles = [
            [
                'name' => 'super_admin',
                'display_name' => 'Super Administrator',
                'description' => 'Full system access with all permissions',
                'permissions' => 'all', // Special flag for all permissions
            ],
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Administrative access to most features',
                'permissions' => [
                    'view-dashboard', 'view-users', 'create-users', 'edit-users',
                    'view-customers', 'create-customers', 'edit-customers', 'delete-customers',
                    'suspend-customers', 'restore-customers',
                    'view-routers', 'create-routers', 'edit-routers',
                    'view-dhcp', 'sync-dhcp',
                    'view-service-plans', 'create-service-plans', 'edit-service-plans',
                    'view-invoices', 'create-invoices', 'edit-invoices',
                    'view-payments', 'create-payments',
                    'view-tickets', 'assign-tickets', 'close-tickets',
                    'view-inventory', 'create-inventory', 'edit-inventory',
                    'view-reports', 'export-reports',
                    'view-settings',
                ],
            ],
            [
                'name' => 'cashier',
                'display_name' => 'Cashier',
                'description' => 'Payment and billing management',
                'permissions' => [
                    'view-dashboard',
                    'view-customers',
                    'view-invoices', 'create-invoices',
                    'view-payments', 'create-payments', 'edit-payments',
                    'view-reports',
                ],
            ],
            [
                'name' => 'office_admin',
                'display_name' => 'Office Administrator',
                'description' => 'Office administration and remittance verification',
                'permissions' => ['view-dashboard', 'view-customers', 'edit-customers', 'view-invoices', 'view-payments', 'create-payments', 'view-remittances', 'receive-remittances'],
            ],
            [
                'name' => 'collector',
                'display_name' => 'User Collector',
                'description' => 'Restricted overdue-account collection and remittance workspace',
                'permissions' => [],
            ],
            [
                'name' => 'technician',
                'display_name' => 'Technician',
                'description' => 'Technical support and installation',
                'permissions' => [
                    'view-dashboard',
                    'view-customers', 'edit-customers',
                    'view-routers',
                    'view-dhcp', 'sync-dhcp',
                    'view-tickets', 'create-tickets', 'edit-tickets', 'close-tickets',
                    'view-inventory',
                ],
            ],
            [
                'name' => 'noc',
                'display_name' => 'Network Operations Center',
                'description' => 'Network monitoring and management',
                'permissions' => [
                    'view-dashboard',
                    'view-customers',
                    'view-routers', 'create-routers', 'edit-routers', 'manage-routers',
                    'view-dhcp', 'sync-dhcp', 'manage-dhcp',
                    'suspend-customers', 'restore-customers',
                    'view-tickets', 'create-tickets',
                    'view-reports',
                ],
            ],
            [
                'name' => 'accounting',
                'display_name' => 'Accounting',
                'description' => 'Financial management and reporting',
                'permissions' => [
                    'view-dashboard',
                    'view-customers',
                    'view-invoices', 'create-invoices', 'edit-invoices', 'download-invoices',
                    'view-payments', 'create-payments', 'edit-payments', 'refund-payments',
                    'view-reports', 'export-reports', 'financial-reports',
                ],
            ],
            [
                'name' => 'viewer',
                'display_name' => 'Read-Only Viewer',
                'description' => 'View-only access to system data',
                'permissions' => [
                    'view-dashboard',
                    'view-customers',
                    'view-routers',
                    'view-dhcp',
                    'view-service-plans',
                    'view-invoices',
                    'view-payments',
                    'view-tickets',
                    'view-inventory',
                    'view-reports',
                ],
            ],
            [
                'name' => 'customer',
                'display_name' => 'Customer',
                'description' => 'Reserved legacy role. Customer-portal access uses the Customer model and its separate token flow.',
                // Do not grant staff API permissions to a public/legacy role.
                'permissions' => [],
            ],
        ];

        foreach ($roles as $roleData) {
            // Idempotent: safe to re-run after initial seed on production.
            $role = Role::updateOrCreate(
                ['name' => $roleData['name']],
                [
                    'display_name' => $roleData['display_name'],
                    'description'  => $roleData['description'],
                    'is_active'    => true,
                ]
            );

            // Assign permissions (sync = replace, so idempotent)
            if ($roleData['permissions'] === 'all') {
                // Super Admin gets all permissions
                $allPermissions = Permission::all();
                $role->permissions()->sync($allPermissions->pluck('id'));
            } else {
                // Assign specific permissions
                $permissions = Permission::whereIn('name', $roleData['permissions'])->get();
                $role->permissions()->sync($permissions->pluck('id'));
            }

            $this->command->info("Role '{$role->display_name}' created with " . $role->permissions()->count() . " permissions.");
        }

        $this->command->info('All roles seeded successfully!');
    }
}
