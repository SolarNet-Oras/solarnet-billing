<?php

/**
 * SolarNet RADIUS integration is intentionally opt-in.
 *
 * The Laravel application stores and audits the subscriber policy, but does
 * not become a UDP RADIUS daemon and does not touch MikroTik until a separate
 * one-customer deployment has been reviewed and enabled. FreeRADIUS NAS
 * secrets are encrypted per NAS in the SolarNet database and are never
 * returned by an API.
 */
return [
    'enabled' => filter_var(env('RADIUS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'host' => trim((string) env('RADIUS_HOST', '')),
    'auth_port' => (int) env('RADIUS_AUTH_PORT', 1812),
    'acct_port' => (int) env('RADIUS_ACCT_PORT', 1813),
    'coa_port' => (int) env('RADIUS_COA_PORT', 3799),
    'timeout' => max(1, (int) env('RADIUS_TIMEOUT', 3)),
    'retries' => max(0, (int) env('RADIUS_RETRIES', 1)),

    'restricted_rate_limit' => trim((string) env('RADIUS_RESTRICTED_RATE_LIMIT', '')),

    // FreeRADIUS is a separate internal Docker service. These switches are
    // deliberately off by default: the service may be deployed and tested on
    // loopback without it becoming a RouterOS DHCP/RADIUS dependency.
    'freeradius_enabled' => filter_var(env('FREERADIUS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'sql_sync_enabled' => filter_var(env('RADIUS_SQL_SYNC_ENABLED', false), FILTER_VALIDATE_BOOL),
    'sql_schema' => trim((string) env('RADIUS_SQL_SCHEMA', 'radius')) ?: 'radius',
    'interim_update_seconds' => max(0, (int) env('RADIUS_INTERIM_UPDATE_SECONDS', 300)),
];
