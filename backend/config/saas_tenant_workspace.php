<?php

return [
    'membership_permissions' => [
        'tenant_admin' => [
            'view-customers', 'create-customers', 'edit-customers',
            'view-invoices', 'create-invoices', 'edit-invoices', 'download-invoices',
            'view-payments', 'create-payments',
            'view-service-plans', 'create-service-plans', 'edit-service-plans',
            'view-tickets', 'create-tickets', 'edit-tickets', 'assign-tickets', 'close-tickets',
            'view-routers', 'create-routers', 'edit-routers', 'delete-routers', 'manage-routers',
            'view-dhcp', 'sync-dhcp', 'manage-dhcp',
            'view-reports', 'view-settings', 'edit-settings',
        ],
        'tenant_staff' => ['view-customers', 'view-invoices', 'download-invoices', 'view-payments', 'view-service-plans', 'view-tickets', 'create-tickets'],
    ],
    'membership_role_aliases' => [
        'tenant_admin' => ['admin', 'office_admin', 'cashier', 'accounting', 'viewer'],
        'tenant_staff' => ['viewer'],
    ],
];
