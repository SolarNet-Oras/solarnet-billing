<?php

return [
    /*
     * The application-facing label for SolarNet's own speed-test interface.
     * It does not describe ownership of a visitor's public IP or upstream ASN.
     * Administrators can override this default through Settings.
     */
    'provider_name' => env('SPEEDTEST_PROVIDER_NAME') ?: 'SolarNet Internet',
    'enabled' => filter_var(env('SPEEDTEST_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
];
