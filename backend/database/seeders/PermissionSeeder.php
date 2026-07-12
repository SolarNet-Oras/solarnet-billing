<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Dashboard
            ['name' => 'view-dashboard', 'display_name' => 'View Dashboard', 'group' => 'dashboard', 'description' => 'Access to main dashboard'],
            
            // Users
            ['name' => 'view-users', 'display_name' => 'View Users', 'group' => 'users', 'description' => 'View user list'],
            ['name' => 'create-users', 'display_name' => 'Create Users', 'group' => 'users', 'description' => 'Create new users'],
            ['name' => 'edit-users', 'display_name' => 'Edit Users', 'group' => 'users', 'description' => 'Edit existing users'],
            ['name' => 'delete-users', 'display_name' => 'Delete Users', 'group' => 'users', 'description' => 'Delete users'],
            
            // Roles & Permissions
            ['name' => 'view-roles', 'display_name' => 'View Roles', 'group' => 'roles', 'description' => 'View role list'],
            ['name' => 'create-roles', 'display_name' => 'Create Roles', 'group' => 'roles', 'description' => 'Create new roles'],
            ['name' => 'edit-roles', 'display_name' => 'Edit Roles', 'group' => 'roles', 'description' => 'Edit existing roles'],
            ['name' => 'delete-roles', 'display_name' => 'Delete Roles', 'group' => 'roles', 'description' => 'Delete roles'],
            ['name' => 'assign-roles', 'display_name' => 'Assign Roles', 'group' => 'roles', 'description' => 'Assign roles to users'],
            
            // Customers
            ['name' => 'view-customers', 'display_name' => 'View Customers', 'group' => 'customers', 'description' => 'View customer list'],
            ['name' => 'create-customers', 'display_name' => 'Create Customers', 'group' => 'customers', 'description' => 'Create new customers'],
            ['name' => 'edit-customers', 'display_name' => 'Edit Customers', 'group' => 'customers', 'description' => 'Edit existing customers'],
            ['name' => 'delete-customers', 'display_name' => 'Delete Customers', 'group' => 'customers', 'description' => 'Delete customers'],
            ['name' => 'suspend-customers', 'display_name' => 'Suspend Customers', 'group' => 'customers', 'description' => 'Suspend customer service'],
            ['name' => 'restore-customers', 'display_name' => 'Restore Customers', 'group' => 'customers', 'description' => 'Restore customer service'],
            
            // Routers (MikroTik)
            ['name' => 'view-routers', 'display_name' => 'View Routers', 'group' => 'routers', 'description' => 'View router list'],
            ['name' => 'create-routers', 'display_name' => 'Create Routers', 'group' => 'routers', 'description' => 'Add new routers'],
            ['name' => 'edit-routers', 'display_name' => 'Edit Routers', 'group' => 'routers', 'description' => 'Edit router configuration'],
            ['name' => 'delete-routers', 'display_name' => 'Delete Routers', 'group' => 'routers', 'description' => 'Delete routers'],
            ['name' => 'manage-routers', 'display_name' => 'Manage Routers', 'group' => 'routers', 'description' => 'Full router management'],
            
            // DHCP
            ['name' => 'view-dhcp', 'display_name' => 'View DHCP Leases', 'group' => 'dhcp', 'description' => 'View DHCP lease list'],
            ['name' => 'sync-dhcp', 'display_name' => 'Sync DHCP', 'group' => 'dhcp', 'description' => 'Synchronize DHCP leases'],
            ['name' => 'manage-dhcp', 'display_name' => 'Manage DHCP', 'group' => 'dhcp', 'description' => 'Full DHCP management'],
            
            // Service Plans
            ['name' => 'view-service-plans', 'display_name' => 'View Service Plans', 'group' => 'service-plans', 'description' => 'View service plan list'],
            ['name' => 'create-service-plans', 'display_name' => 'Create Service Plans', 'group' => 'service-plans', 'description' => 'Create new service plans'],
            ['name' => 'edit-service-plans', 'display_name' => 'Edit Service Plans', 'group' => 'service-plans', 'description' => 'Edit existing service plans'],
            ['name' => 'delete-service-plans', 'display_name' => 'Delete Service Plans', 'group' => 'service-plans', 'description' => 'Delete service plans'],
            
            // Billing & Invoices
            ['name' => 'view-invoices', 'display_name' => 'View Invoices', 'group' => 'billing', 'description' => 'View invoice list'],
            ['name' => 'create-invoices', 'display_name' => 'Create Invoices', 'group' => 'billing', 'description' => 'Create new invoices'],
            ['name' => 'edit-invoices', 'display_name' => 'Edit Invoices', 'group' => 'billing', 'description' => 'Edit existing invoices'],
            ['name' => 'delete-invoices', 'display_name' => 'Delete Invoices', 'group' => 'billing', 'description' => 'Delete invoices'],
            ['name' => 'download-invoices', 'display_name' => 'Download Invoices', 'group' => 'billing', 'description' => 'Download invoice PDFs'],
            
            // Payments
            ['name' => 'view-payments', 'display_name' => 'View Payments', 'group' => 'payments', 'description' => 'View payment list'],
            ['name' => 'create-payments', 'display_name' => 'Create Payments', 'group' => 'payments', 'description' => 'Record new payments'],
            ['name' => 'edit-payments', 'display_name' => 'Edit Payments', 'group' => 'payments', 'description' => 'Edit existing payments'],
            ['name' => 'delete-payments', 'display_name' => 'Delete Payments', 'group' => 'payments', 'description' => 'Delete payments'],
            ['name' => 'refund-payments', 'display_name' => 'Refund Payments', 'group' => 'payments', 'description' => 'Process payment refunds'],
            
            // Tickets
            ['name' => 'view-tickets', 'display_name' => 'View Tickets', 'group' => 'tickets', 'description' => 'View support tickets'],
            ['name' => 'create-tickets', 'display_name' => 'Create Tickets', 'group' => 'tickets', 'description' => 'Create new tickets'],
            ['name' => 'edit-tickets', 'display_name' => 'Edit Tickets', 'group' => 'tickets', 'description' => 'Edit existing tickets'],
            ['name' => 'delete-tickets', 'display_name' => 'Delete Tickets', 'group' => 'tickets', 'description' => 'Delete tickets'],
            ['name' => 'assign-tickets', 'display_name' => 'Assign Tickets', 'group' => 'tickets', 'description' => 'Assign tickets to technicians'],
            ['name' => 'close-tickets', 'display_name' => 'Close Tickets', 'group' => 'tickets', 'description' => 'Close resolved tickets'],
            
            // Inventory
            ['name' => 'view-inventory', 'display_name' => 'View Inventory', 'group' => 'inventory', 'description' => 'View equipment inventory'],
            ['name' => 'create-inventory', 'display_name' => 'Create Inventory', 'group' => 'inventory', 'description' => 'Add new equipment'],
            ['name' => 'edit-inventory', 'display_name' => 'Edit Inventory', 'group' => 'inventory', 'description' => 'Edit equipment records'],
            ['name' => 'delete-inventory', 'display_name' => 'Delete Inventory', 'group' => 'inventory', 'description' => 'Delete equipment records'],
            
            // Reports
            ['name' => 'view-reports', 'display_name' => 'View Reports', 'group' => 'reports', 'description' => 'Access reports dashboard'],
            ['name' => 'export-reports', 'display_name' => 'Export Reports', 'group' => 'reports', 'description' => 'Export reports to PDF/Excel'],
            ['name' => 'financial-reports', 'display_name' => 'Financial Reports', 'group' => 'reports', 'description' => 'Access financial reports'],
            
            // Settings
            ['name' => 'view-settings', 'display_name' => 'View Settings', 'group' => 'settings', 'description' => 'View system settings'],
            ['name' => 'edit-settings', 'display_name' => 'Edit Settings', 'group' => 'settings', 'description' => 'Modify system settings'],
            
            // Audit Logs
            ['name' => 'view-audit-logs', 'display_name' => 'View Audit Logs', 'group' => 'audit', 'description' => 'Access audit logs'],
        ];

        foreach ($permissions as $permission) {
            // Idempotent: safe to re-run after initial seed on production.
            // Using name as the unique key + full attribute set as the update payload.
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                $permission
            );
        }

        $this->command->info('Permissions seeded successfully!');
    }
}
