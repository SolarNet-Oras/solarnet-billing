<?php

return [
    /*
    | Keep the platform inert until tenant backfills, authorization, storage,
    | queues, caches, and integrations have each passed their rollout gate.
    */
    'enabled' => (bool) env('SAAS_ENABLED', false),
    'enforcement_mode' => env('SAAS_ENFORCEMENT_MODE', 'disabled'),
    'registration_enabled' => (bool) env('SAAS_REGISTRATION_ENABLED', false),
    'default_trial_days' => (int) env('SAAS_DEFAULT_TRIAL_DAYS', 30),
    'default_currency' => env('SAAS_DEFAULT_CURRENCY', 'PHP'),
    'primary_tenant_slug' => env('SAAS_PRIMARY_TENANT_SLUG', 'solarnet'),
];
