<?php

return [
    /*
     * The feed is consulted only when an operator starts a scan. It is never
     * used to make automatic RouterOS firewall changes.
     */
    'feodo_url' => env('THREAT_FEED_FEODO_URL', 'https://feodotracker.abuse.ch/downloads/ipblocklist.txt'),
    'cache_seconds' => (int) env('THREAT_FEED_CACHE_SECONDS', 900),
    // A busy concentrator can have many thousands of active sessions. The
    // scan is intentionally a bounded sample, so it does not overload the
    // router or a VPN/port-forwarded API connection.
    'connection_limit' => (int) env('THREAT_MONITOR_CONNECTION_LIMIT', 2000),
    'connection_socket_timeout' => (int) env('THREAT_MONITOR_CONNECTION_SOCKET_TIMEOUT', 15),
    // A reviewed feed match is not a permanent reputation verdict. New
    // SolarNet-owned RouterOS address-list entries therefore expire unless an
    // administrator deliberately re-approves the indicator after a later scan.
    'manual_block_timeout' => env('THREAT_MONITOR_MANUAL_BLOCK_TIMEOUT', '1d'),
];
