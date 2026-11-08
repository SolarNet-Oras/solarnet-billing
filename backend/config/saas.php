<?php

return [
    /*
    | Keep the platform inert until tenant backfills, authorization, storage,
    | queues, caches, and integrations have each passed their rollout gate.
    */
    'enabled' => (bool) env('SAAS_ENABLED', false),
    'platform_enabled' => filter_var(env('SAAS_PLATFORM_ENABLED', false), FILTER_VALIDATE_BOOL),
    'tenant_app_enabled' => filter_var(env('SAAS_TENANT_APP_ENABLED', false), FILTER_VALIDATE_BOOL),
    'enforcement_mode' => env('SAAS_ENFORCEMENT_MODE', 'disabled'),
    'registration_enabled' => filter_var(env('SAAS_REGISTRATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'default_trial_days' => (int) env('SAAS_DEFAULT_TRIAL_DAYS', 30),
    'default_currency' => env('SAAS_DEFAULT_CURRENCY', 'PHP'),
    'primary_tenant_slug' => env('SAAS_PRIMARY_TENANT_SLUG', 'solarnet'),
    'domains_enabled' => filter_var(env('SAAS_DOMAINS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'ownership_auto_assignment' => filter_var(env('SAAS_OWNERSHIP_AUTO_ASSIGNMENT', true), FILTER_VALIDATE_BOOL),
    // Staged by default. Canary mode permits only explicitly listed tenant
    // slugs; active mode still requires all global enforcement flags.
    'workspace_activation_mode' => env('SAAS_WORKSPACE_ACTIVATION_MODE', 'staged'),
    'workspace_canary_tenants' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SAAS_WORKSPACE_CANARY_TENANTS', ''))
    ))),
    'domains' => [
        'billing' => env('SAAS_BILLING_DOMAIN', 'billing.solarnetportal.com'),
        'app' => env('SAAS_APP_DOMAIN', 'app.solarnetportal.com'),
        'admin' => env('SAAS_ADMIN_DOMAIN', 'admin.solarnetportal.com'),
    ],
];
