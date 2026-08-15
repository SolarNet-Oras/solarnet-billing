<?php

return [
    /*
     * The feed is consulted only when an operator starts a scan. It is never
     * used to make automatic RouterOS firewall changes.
     */
    'feodo_url' => env('THREAT_FEED_FEODO_URL', 'https://feodotracker.abuse.ch/downloads/ipblocklist.txt'),
    'cache_seconds' => (int) env('THREAT_FEED_CACHE_SECONDS', 900),
    'connection_limit' => (int) env('THREAT_MONITOR_CONNECTION_LIMIT', 5000),
];
