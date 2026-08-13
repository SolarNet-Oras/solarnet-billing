<?php

namespace App\Services;

use App\Models\Router;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;
use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MikrotikService
{
    private const BILLING_RULE_PREFIX = 'Solarnet Billing: suspended';
    private const SUSPENDED_ADDRESS_LIST = 'suspended_customers';
    private const PAYMENT_PORTAL_ADDRESS_LIST = 'solarnet_payment_portal';
    private const PAYMENT_PORTAL_COMMENT_PREFIX = 'Solarnet Billing payment portal';

    protected function makeConfig(Router $router): Config
    {
        return (new Config())
            ->set('host', $router->host)
            ->set('user', $router->username)
            ->set('pass', $router->password)
            ->set('port', $router->port)
            ->set('timeout', 3)
            ->set('socket_timeout', 5)
            ->set('attempts', 1)
            ->set('delay', 1);
    }

    /**
     * Test connection to MikroTik router
     * 
     * @param Router $router
     * @return array{success: bool, message: string, data: array|null}
     */
    public function testConnection(Router $router): array
    {
        try {
            // Create config
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            // Create client and connect
            $client = new Client($config);
            
            // Fetch system resource to get RouterOS version and uptime
            $query = new Query('/system/resource/print');
            $response = $client->query($query)->read();
            
            $systemInfo = $response[0] ?? [];
            
            $data = [
                'version' => $systemInfo['version'] ?? 'Unknown',
                'uptime' => $systemInfo['uptime'] ?? 'Unknown',
                'cpu_load' => $systemInfo['cpu-load'] ?? 'Unknown',
                'free_memory' => $systemInfo['free-memory'] ?? 'Unknown',
                'total_memory' => $systemInfo['total-memory'] ?? 'Unknown',
                'board_name' => $systemInfo['board-name'] ?? 'Unknown',
            ];
            
            // Update router record
            $router->update([
                'connection_status' => 'online',
                'routeros_version' => $data['version'],
                'last_connected_at' => now(),
            ]);
            
            return [
                'success' => true,
                'message' => 'Connected successfully to ' . $router->name,
                'data' => $data,
            ];
            
        } catch (Throwable $e) {
            Log::error('MikroTik connection failed', [
                'router_id' => $router->id,
                'host' => $router->host,
                'error' => $e->getMessage(),
            ]);
            
            // Update router status
            $router->update([
                'connection_status' => 'offline',
            ]);
            
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Sync everything from the router — system status, queues, DHCP leases.
     * Persists snapshot counts into the routers row and returns per-item counts.
     */
    public function syncRouter(Router $router): array
    {
        $result = [
            'success'      => true,
            'message'      => '',
            'synced_items' => [
                'dhcp_leases' => 0,
                'queues'      => 0,
                'system'      => false,
            ],
            'errors'       => [],
        ];

        // 1) System / version — also functions as a live connectivity check
        $conn = $this->testConnection($router);
        if (!$conn['success']) {
            return [
                'success' => false,
                'message' => $conn['message'],
                'synced_items' => $result['synced_items'],
                'errors' => [$conn['message']],
            ];
        }
        $result['synced_items']['system'] = true;

        // 2) DHCP leases
        try {
            $leases = $this->getDhcpLeasesDetailed($router);
            if ($leases['success']) {
                $result['synced_items']['dhcp_leases'] = $leases['count'];
            } else {
                $result['errors'][] = 'dhcp_leases: ' . ($leases['message'] ?? 'failed');
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'dhcp_leases exception: ' . $e->getMessage();
        }

        // 3) Queues
        try {
            $queues = $this->getQueues($router);
            if ($queues['success']) {
                $result['synced_items']['queues'] = is_array($queues['data']) ? count($queues['data']) : 0;
            } else {
                $result['errors'][] = 'queues: ' . ($queues['message'] ?? 'failed');
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'queues exception: ' . $e->getMessage();
        }

        // 4) Persist snapshot on the router record
        $router->update(['last_sync_at' => now()]);

        $result['message'] = sprintf(
            'Synced %d DHCP leases, %d queues from %s',
            $result['synced_items']['dhcp_leases'],
            $result['synced_items']['queues'],
            $router->name
        );
        if (!empty($result['errors'])) {
            $result['success'] = false;
        }

        return $result;
    }

    /**
     * Get DHCP leases from router
     * Placeholder for Phase 6
     * 
     * @param Router $router
     * @return array
     */
    public function getDhcpLeases(Router $router): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            $query = new Query('/ip/dhcp-server/lease/print');
            $leases = $client->query($query)->read();
            
            return [
                'success' => true,
                'data' => $leases,
            ];
            
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * Add a simple queue for a customer
     * 
     * @param Router $router
     * @param array $queueData
     * @return array{success: bool, message: string, queue_id: string|null}
     */
    public function addQueue(Router $router, array $queueData): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            // Build queue parameters
            $params = [
                'name' => $queueData['name'],
                'target' => $queueData['target'], // IP address
                'max-limit' => $queueData['max_limit'], // e.g., "100M/50M"
                'comment' => $queueData['comment'] ?? '',
            ];
            
            // Add burst if provided
            if (!empty($queueData['burst_limit'])) {
                $params['burst-limit'] = $queueData['burst_limit'];
                $params['burst-threshold'] = $queueData['burst_threshold'];
                $params['burst-time'] = $queueData['burst_time'];
            }
            
            // Add priority if provided
            if (!empty($queueData['priority'])) {
                $params['priority'] = $queueData['priority'] . '/' . $queueData['priority'];
            }
            
            // Create the queue
            $query = (new Query('/queue/simple/add'));
            foreach ($params as $key => $value) {
                $query->equal($key, $value);
            }
            
            $response = $client->query($query)->read();
            
            // Get the ID of created queue
            $queueId = $response[0]['after']['ret'] ?? null;
            
            Log::info('Queue created on MikroTik', [
                'router' => $router->name,
                'queue_name' => $queueData['name'],
                'target' => $queueData['target'],
                'queue_id' => $queueId,
            ]);
            
            return [
                'success' => true,
                'message' => 'Queue created successfully',
                'queue_id' => $queueId,
            ];
            
        } catch (Throwable $e) {
            Log::error('Failed to create queue on MikroTik', [
                'router' => $router->name,
                'error' => $e->getMessage(),
                'queue_data' => $queueData,
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to create queue: ' . $e->getMessage(),
                'queue_id' => null,
            ];
        }
    }

    /**
     * Update an existing queue
     * 
     * @param Router $router
     * @param string $queueName
     * @param array $updates
     * @return array{success: bool, message: string}
     */
    public function updateQueue(Router $router, string $queueName, array $updates): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            // Find the queue by name
            $query = (new Query('/queue/simple/print'))
                ->where('name', $queueName);
            $queues = $client->query($query)->read();
            
            if (empty($queues)) {
                return [
                    'success' => false,
                    'message' => 'Queue not found: ' . $queueName,
                ];
            }
            
            $queueId = $queues[0]['.id'];
            
            // Build update query
            $query = (new Query('/queue/simple/set'))
                ->equal('.id', $queueId);
            
            foreach ($updates as $key => $value) {
                $query->equal($key, $value);
            }
            
            $client->query($query)->read();
            
            Log::info('Queue updated on MikroTik', [
                'router' => $router->name,
                'queue_name' => $queueName,
                'updates' => $updates,
            ]);
            
            return [
                'success' => true,
                'message' => 'Queue updated successfully',
            ];
            
        } catch (Throwable $e) {
            Log::error('Failed to update queue on MikroTik', [
                'router' => $router->name,
                'queue_name' => $queueName,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to update queue: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Remove a queue
     * 
     * @param Router $router
     * @param string $queueName
     * @return array{success: bool, message: string}
     */
    public function removeQueue(Router $router, string $queueName): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            // Find the queue by name
            $query = (new Query('/queue/simple/print'))
                ->where('name', $queueName);
            $queues = $client->query($query)->read();
            
            if (empty($queues)) {
                return [
                    'success' => true, // Already removed
                    'message' => 'Queue already removed or not found',
                ];
            }
            
            $queueId = $queues[0]['.id'];
            
            // Remove the queue
            $query = (new Query('/queue/simple/remove'))
                ->equal('.id', $queueId);
            
            $client->query($query)->read();
            
            Log::info('Queue removed from MikroTik', [
                'router' => $router->name,
                'queue_name' => $queueName,
            ]);
            
            return [
                'success' => true,
                'message' => 'Queue removed successfully',
            ];
            
        } catch (Throwable $e) {
            Log::error('Failed to remove queue from MikroTik', [
                'router' => $router->name,
                'queue_name' => $queueName,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to remove queue: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get all queues from router
     * 
     * @param Router $router
     * @return array
     */
    public function getQueues(Router $router): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            $query = new Query('/queue/simple/print');
            $queues = $client->query($query)->read();

            // The dashboard reads this snapshot rather than making its own
            // router connection on every page refresh. A failed VPN/API link
            // therefore never turns the dashboard into a slow or failing page.
            Cache::put("router:queues:{$router->id}", [
                'captured_at' => now()->toIso8601String(),
                'data' => $queues,
            ], now()->addMinutes(15));
            
            return [
                'success' => true,
                'data' => $queues,
            ];
            
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * Get DHCP leases from router (already implemented above, but ensuring it's here)
     * Returns leases in standardized format
     */
    public function getDhcpLeasesDetailed(Router $router): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            $query = new Query('/ip/dhcp-server/lease/print');
            $leases = $client->query($query)->read();
            
            // Parse and format leases
            $formattedLeases = [];
            foreach ($leases as $lease) {
                // MikroTik returns booleans as "true"/"false" strings
                $isDynamic = isset($lease['dynamic'])
                    ? filter_var($lease['dynamic'], FILTER_VALIDATE_BOOLEAN)
                    : true;

                $formattedLeases[] = [
                    'mac_address'   => $lease['mac-address'] ?? $lease['active-mac-address'] ?? null,
                    'ip_address'    => $lease['address'] ?? $lease['active-address'] ?? null,
                    'hostname'      => $lease['host-name'] ?? null,
                    'comment'       => $lease['comment'] ?? null,
                    'rate_limit'    => $lease['rate-limit'] ?? null,
                    'is_dynamic'    => $isDynamic,
                    'status'        => $lease['status'] ?? 'unknown',
                    'server'        => $lease['server'] ?? 'default',
                    'expires_after' => $lease['expires-after'] ?? null,
                    'last_seen'     => $lease['last-seen'] ?? null,
                ];
            }
            
            return [
                'success' => true,
                'data' => $formattedLeases,
                'count' => count($formattedLeases),
            ];
            
        } catch (Throwable $e) {
            Log::error('Failed to fetch DHCP leases', [
                'router' => $router->name,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
                'count' => 0,
            ];
        }
    }


    /**
     * Ensure a DHCP lease is STATIC on MikroTik with the given comment + rate-limit.
     *
     * Business rule (Solarnet): when a client is registered from the Unregistered
     * page, their MikroTik lease must automatically become static, receive their
     * name as the comment, and get their subscription's rate-limit applied — so
     * bandwidth is enforced immediately, no follow-up manual step needed.
     *
     * Behaviour:
     *  - If a lease with the given MAC exists AND is dynamic → make it static, then set fields.
     *  - If it exists and is already static → just set fields.
     *  - If no lease exists (rare — customer added manually with only a MAC) → add a static one.
     *
     * Returns { success: bool, message: string, lease_id?: string } — never throws.
     */
    public function updateOrMakeStaticLease(
        Router $router,
        string $macAddress,
        string $comment,
        ?string $rateLimit = null,
        ?string $ipAddress = null,
        string $server = 'default',
        bool $preserveComment = false
    ): array {
        // Refuse to reach an unreachable router — same guard as QueueService.
        if (in_array($router->connection_status, ['offline', 'unknown', null], true)) {
            return [
                'success' => false,
                'message' => 'Router is not online (connection_status=' . ($router->connection_status ?? 'null') . '). Skipped MikroTik lease sync.',
                'skipped' => true,
            ];
        }

        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)
                ->set('socket_timeout', 5)
                ->set('attempts', 1)
                ->set('delay', 1);

            $client = new Client($config);
            $macNorm = strtoupper(trim($macAddress));

            // 1) Look up existing lease by MAC
            $find = (new Query('/ip/dhcp-server/lease/print'))
                ->where('mac-address', $macNorm);
            $existing = $client->query($find)->read();
            $lease    = $existing[0] ?? null;

            // Only overwrite the MikroTik comment when we're explicitly allowed to.
            // For static+commented leases the technician's original comment must survive.
            $updates = [];
            if (!$preserveComment) {
                $updates['comment'] = $comment;
            }
            if ($rateLimit) {
                // Force the plan's rate-limit — even if lease already had one.
                $updates['rate-limit'] = $rateLimit;
            }

            if ($lease) {
                $leaseId   = $lease['.id'];
                $isDynamic = isset($lease['dynamic'])
                    ? filter_var($lease['dynamic'], FILTER_VALIDATE_BOOLEAN)
                    : false;

                // Dynamic → convert to static first (MikroTik dedicated command)
                if ($isDynamic) {
                    $mk = (new Query('/ip/dhcp-server/lease/make-static'))
                        ->equal('.id', $leaseId);
                    $client->query($mk)->read();
                    // After make-static, the .id may change — re-lookup by MAC
                    $existing = $client->query($find)->read();
                    $lease    = $existing[0] ?? $lease;
                    $leaseId  = $lease['.id'] ?? $leaseId;
                }

                // 2) Apply updates (comment optionally + rate-limit)
                if (empty($updates)) {
                    return [
                        'success'         => true,
                        'message'         => 'Made static; comment preserved and no rate-limit to apply.',
                        'lease_id'        => $leaseId,
                        'was_dynamic'     => $isDynamic,
                        'comment_kept'    => $preserveComment,
                    ];
                }

                $set = (new Query('/ip/dhcp-server/lease/set'))
                    ->equal('.id', $leaseId);
                foreach ($updates as $k => $v) {
                    $set->equal($k, $v);
                }
                $client->query($set)->read();

                return [
                    'success'      => true,
                    'message'      => $preserveComment
                        ? 'Static lease kept its comment; rate-limit forced to plan.'
                        : 'Lease updated (comment + rate-limit applied).',
                    'lease_id'     => $leaseId,
                    'was_dynamic'  => $isDynamic,
                    'comment_kept' => $preserveComment,
                    'applied'      => array_keys($updates),
                ];
            }

            // 3) No existing lease — add a fresh static one (requires IP).
            //    When there's no lease on MikroTik we always set the comment.
            if (!$ipAddress) {
                return [
                    'success' => false,
                    'message' => 'No existing lease for MAC ' . $macNorm . ' and no IP provided to create a new static lease.',
                ];
            }
            $add = (new Query('/ip/dhcp-server/lease/add'))
                ->equal('mac-address', $macNorm)
                ->equal('address', $ipAddress)
                ->equal('server', $server)
                ->equal('comment', $comment);
            if ($rateLimit) {
                $add->equal('rate-limit', $rateLimit);
            }
            $result = $client->query($add)->read();

            return [
                'success'  => true,
                'message'  => 'Static lease added',
                'lease_id' => $result[0]['ret'] ?? null,
                'created'  => true,
            ];
        } catch (\Throwable $e) {
            Log::warning('updateOrMakeStaticLease failed', [
                'router_id'         => $router->id,
                'mac'               => $macAddress,
                'preserve_comment'  => $preserveComment,
                'rate_limit'        => $rateLimit,
                'error'             => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => 'MikroTik error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Add an IP address to a MikroTik firewall address-list.
     */
    public function addAddressList(Router $router, string $listName, string $address, ?string $comment = null): array
    {
        try {
            $client = new Client($this->makeConfig($router));

            $query = (new Query('/ip/firewall/address-list/print'))
                ->where('list', $listName)
                ->where('address', $address);
            $existing = $client->query($query)->read();
            if (!empty($existing)) {
                return [
                    'success' => true,
                    'message' => 'Address already present in list',
                ];
            }

            $add = (new Query('/ip/firewall/address-list/add'))
                ->equal('list', $listName)
                ->equal('address', $address);
            if ($comment !== null && $comment !== '') {
                $add->equal('comment', $comment);
            }
            $client->query($add)->read();

            return [
                'success' => true,
                'message' => 'Address added to address-list',
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to add MikroTik address-list entry', [
                'router_id' => $router->id,
                'list' => $listName,
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to add address-list entry: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Remove an IP address from a MikroTik firewall address-list.
     */
    public function removeAddressList(Router $router, string $listName, string $address): array
    {
        try {
            $client = new Client($this->makeConfig($router));

            $query = (new Query('/ip/firewall/address-list/print'))
                ->where('list', $listName)
                ->where('address', $address);
            $entries = $client->query($query)->read();

            if (empty($entries)) {
                return [
                    'success' => true,
                    'message' => 'Address already absent from list',
                ];
            }

            foreach ($entries as $entry) {
                if (!empty($entry['.id'])) {
                    $remove = (new Query('/ip/firewall/address-list/remove'))
                        ->equal('.id', $entry['.id']);
                    $client->query($remove)->read();
                }
            }

            return [
                'success' => true,
                'message' => 'Address removed from address-list',
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to remove MikroTik address-list entry', [
                'router_id' => $router->id,
                'list' => $listName,
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to remove address-list entry: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Install the payment-only firewall policy through the RouterOS API.
     * Only rules whose comments start with our prefix are removed or changed.
     */
    public function installBillingAccessRules(Router $router, string $paymentPortalUrl): array
    {
        $host = parse_url($paymentPortalUrl, PHP_URL_HOST);
        if (!$host) {
            return ['success' => false, 'message' => 'Payment reminder URL must be a valid absolute URL.'];
        }

        $paymentIp = gethostbyname($host);
        if (!filter_var($paymentIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['success' => false, 'message' => "Could not resolve the payment portal host: {$host}"];
        }

        try {
            $client = new Client($this->makeConfig($router));
            if ($this->paymentPortalAddressListHasUnmanagedEntries($client)) {
                return [
                    'success' => false,
                    'message' => 'The solarnet_payment_portal address list contains entries not created by SolarNet. No firewall changes were made.',
                ];
            }
            $this->ensurePaymentPortalAddressList($client, $paymentIp, $host);
            $this->removeBillingFilterRules($client);

            // RouterOS versions differ in how they interpret numeric
            // `place-before` values over the API. Add the managed rules, then
            // explicitly move them into their required order below.
            $rules = [
                ['protocol' => null,  'dst_port' => null,     'dst_address' => null,       'action' => 'drop',   'comment' => self::BILLING_RULE_PREFIX . ' block internet'],
                ['protocol' => 'tcp', 'dst_port' => '53',     'dst_address' => null,       'action' => 'accept', 'comment' => self::BILLING_RULE_PREFIX . ' allow DNS TCP'],
                ['protocol' => 'udp', 'dst_port' => '53',     'dst_address' => null,       'action' => 'accept', 'comment' => self::BILLING_RULE_PREFIX . ' allow DNS UDP'],
                ['protocol' => 'tcp', 'dst_port' => '80,443', 'dst_address_list' => self::PAYMENT_PORTAL_ADDRESS_LIST, 'action' => 'accept', 'comment' => self::BILLING_RULE_PREFIX . ' allow payment portal'],
            ];

            foreach ($rules as $rule) {
                $query = (new Query('/ip/firewall/filter/add'))
                    ->equal('chain', 'forward')
                    ->equal('src-address-list', self::SUSPENDED_ADDRESS_LIST)
                    ->equal('action', $rule['action'])
                    ->equal('comment', $rule['comment']);
                if ($rule['protocol']) $query->equal('protocol', $rule['protocol']);
                if ($rule['dst_port']) $query->equal('dst-port', $rule['dst_port']);
                if (!empty($rule['dst_address_list'])) $query->equal('dst-address-list', $rule['dst_address_list']);
                $client->query($query)->read();
            }

            $this->orderBillingFilterRules($client);

            $this->ensureSuspendedAddressList($client);

            return [
                'success' => true,
                'message' => "Installed payment-only access rules for {$host} ({$paymentIp}).",
                'payment_portal_host' => $host,
                'payment_portal_ip' => $paymentIp,
                'rules_installed' => 4,
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to install billing firewall rules', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to install billing firewall rules: ' . $e->getMessage()];
        }
    }

    public function billingAccessRulesStatus(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $rules = $this->billingFilterRules($client);
            $paymentPortalEntries = $this->paymentPortalAddressListEntries($client);
            $audit = $this->billingNetworkAudit($client);
            return [
                'success' => true,
                'installed' => count($rules) === 4 && count($paymentPortalEntries) === 1,
                'rule_count' => count($rules),
                'payment_portal_entries' => array_map(fn (array $entry) => [
                    'address' => $entry['address'] ?? null,
                    'comment' => $entry['comment'] ?? '',
                    'disabled' => ($entry['disabled'] ?? 'false') === 'true',
                ], $paymentPortalEntries),
                'audit' => $audit,
                'rules' => array_map(fn (array $rule) => [
                    'id' => $rule['.id'] ?? null,
                    'action' => $rule['action'] ?? null,
                    'protocol' => $rule['protocol'] ?? 'any',
                    'dst_address' => $rule['dst-address'] ?? 'any',
                    'dst_port' => $rule['dst-port'] ?? 'any',
                    'disabled' => ($rule['disabled'] ?? 'false') === 'true',
                    'comment' => $rule['comment'] ?? '',
                ], $rules),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Failed to verify billing firewall rules: ' . $e->getMessage()];
        }
    }

    /**
     * Read-only safety inspection. The billing policy is address-list based,
     * so it protects all detected customer DHCP VLANs without enabling a
     * RouterOS Hotspot on an interface.
     */
    public function billingAccessAudit(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            return ['success' => true, 'audit' => $this->billingNetworkAudit($client)];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Failed to read router network configuration: ' . $e->getMessage()];
        }
    }

    public function removeBillingAccessRules(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $removed = $this->removeBillingFilterRules($client);
            return ['success' => true, 'message' => "Removed {$removed} Solarnet billing firewall rule(s).", 'removed' => $removed];
        } catch (Throwable $e) {
            Log::warning('Failed to remove billing firewall rules', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to remove billing firewall rules: ' . $e->getMessage()];
        }
    }

    private function billingFilterRules(Client $client): array
    {
        $rules = $client->query(new Query('/ip/firewall/filter/print'))->read();
        return array_values(array_filter($rules, fn (array $rule) => str_starts_with((string) ($rule['comment'] ?? ''), self::BILLING_RULE_PREFIX)));
    }

    private function removeBillingFilterRules(Client $client): int
    {
        $rules = $this->billingFilterRules($client);
        foreach ($rules as $rule) {
            if (!empty($rule['.id'])) {
                $client->query((new Query('/ip/firewall/filter/remove'))->equal('.id', $rule['.id']))->read();
            }
        }
        return count($rules);
    }

    /** Ensure allow rules always precede the suspended-client drop rule. */
    private function orderBillingFilterRules(Client $client): void
    {
        $order = [
            self::BILLING_RULE_PREFIX . ' allow payment portal',
            self::BILLING_RULE_PREFIX . ' allow DNS UDP',
            self::BILLING_RULE_PREFIX . ' allow DNS TCP',
            self::BILLING_RULE_PREFIX . ' block internet',
        ];

        foreach ($order as $position => $comment) {
            $rules = $client->query((new Query('/ip/firewall/filter/print'))->where('comment', $comment))->read();
            $ruleId = $rules[0]['.id'] ?? null;
            if (!$ruleId) {
                throw new \RuntimeException("Billing firewall rule is missing: {$comment}");
            }

            $client->query(
                (new Query('/ip/firewall/filter/move'))
                    ->equal('numbers', $ruleId)
                    ->equal('destination', (string) $position)
            )->read();
        }
    }

    private function ensureSuspendedAddressList(Client $client): void
    {
        $entries = $client->query((new Query('/ip/firewall/address-list/print'))->where('list', self::SUSPENDED_ADDRESS_LIST))->read();
        if (empty($entries)) {
            $client->query(
                (new Query('/ip/firewall/address-list/add'))
                    ->equal('list', self::SUSPENDED_ADDRESS_LIST)
                    ->equal('address', '0.0.0.0')
                    ->equal('disabled', 'true')
                    ->equal('comment', 'Solarnet Billing placeholder - do not enable')
            )->read();
        }
    }

    /**
     * Refresh only the address-list entries owned by the billing application.
     * The firewall rule points at this list, so a later refresh never needs to
     * touch customer entries or unrelated firewall rules.
     */
    private function ensurePaymentPortalAddressList(Client $client, string $paymentIp, string $host): void
    {
        foreach ($this->paymentPortalAddressListEntries($client) as $entry) {
            if (!empty($entry['.id'])) {
                $client->query((new Query('/ip/firewall/address-list/remove'))->equal('.id', $entry['.id']))->read();
            }
        }

        $client->query(
            (new Query('/ip/firewall/address-list/add'))
                ->equal('list', self::PAYMENT_PORTAL_ADDRESS_LIST)
                ->equal('address', $paymentIp)
                ->equal('comment', self::PAYMENT_PORTAL_COMMENT_PREFIX . ' ' . $host)
        )->read();
    }

    private function paymentPortalAddressListEntries(Client $client): array
    {
        $entries = $client->query((new Query('/ip/firewall/address-list/print'))->where('list', self::PAYMENT_PORTAL_ADDRESS_LIST))->read();

        return array_values(array_filter($entries, fn (array $entry) => str_starts_with(
            (string) ($entry['comment'] ?? ''),
            self::PAYMENT_PORTAL_COMMENT_PREFIX,
        )));
    }

    private function paymentPortalAddressListHasUnmanagedEntries(Client $client): bool
    {
        $entries = $client->query((new Query('/ip/firewall/address-list/print'))->where('list', self::PAYMENT_PORTAL_ADDRESS_LIST))->read();

        return collect($entries)->contains(fn (array $entry) => !str_starts_with(
            (string) ($entry['comment'] ?? ''),
            self::PAYMENT_PORTAL_COMMENT_PREFIX,
        ));
    }

    private function billingNetworkAudit(Client $client): array
    {
        $dhcpServers = $client->query(new Query('/ip/dhcp-server/print'))->read();
        $addresses = $client->query(new Query('/ip/address/print'))->read();
        $hotspots = $client->query(new Query('/ip/hotspot/print'))->read();
        $dhcpInterfaces = array_values(array_unique(array_filter(array_map(
            fn (array $server) => $server['interface'] ?? null,
            $dhcpServers,
        ))));
        $addressByInterface = [];
        foreach ($addresses as $address) {
            $interface = $address['interface'] ?? null;
            if ($interface && in_array($interface, $dhcpInterfaces, true)) {
                $addressByInterface[$interface] = $address['address'] ?? null;
            }
        }

        return [
            'dhcp_server_count' => count($dhcpServers),
            'customer_interfaces' => array_map(fn (string $interface) => [
                'interface' => $interface,
                'gateway' => $addressByInterface[$interface] ?? null,
            ], $dhcpInterfaces),
            'hotspot_count' => count($hotspots),
            'hotspot_interfaces' => array_values(array_filter(array_map(fn (array $hotspot) => $hotspot['interface'] ?? null, $hotspots))),
            'recommended_mode' => 'address-list firewall policy',
            'hotspot_change_required' => false,
            'safety_note' => count($hotspots) > 0
                ? 'Existing Hotspot configuration was detected and will not be changed.'
                : 'No Hotspot configuration will be created. The policy is limited to suspended IP addresses only.',
        ];
    }

    /** Run a one-time RouterOS script and delete the temporary script afterward. */
    public function runOneTimeScript(Router $router, string $source, ?string $executedBy = null): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $name = 'solarnet-once-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 12);

            $client->query(
                (new Query('/system/script/add'))
                    ->equal('name', $name)
                    ->equal('source', $source)
                    ->equal('comment', 'Solarnet one-time console command')
            )->read();

            try {
                $scripts = $client->query((new Query('/system/script/print'))->where('name', $name))->read();
                $scriptId = $scripts[0]['.id'] ?? null;
                if (!$scriptId) {
                    throw new \RuntimeException('RouterOS did not return the temporary script.');
                }
                $result = $client->query((new Query('/system/script/run'))->equal('.id', $scriptId))->read();
            } finally {
                // Always remove the temporary script, including when RouterOS
                // reports a script error. The submitted source is never saved.
                $scripts = $client->query((new Query('/system/script/print'))->where('name', $name))->read();
                foreach ($scripts as $script) {
                    if (!empty($script['.id'])) {
                        $client->query((new Query('/system/script/remove'))->equal('.id', $script['.id']))->read();
                    }
                }
            }

            Log::info('One-time MikroTik console script executed', [
                'router_id' => $router->id,
                'executed_by' => $executedBy,
                'source_length' => strlen($source),
            ]);

            return [
                'success' => true,
                'message' => 'Script executed. The temporary RouterOS script was removed.',
                'result' => $result,
            ];
        } catch (Throwable $e) {
            Log::warning('MikroTik console script failed', [
                'router_id' => $router->id,
                'executed_by' => $executedBy,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Script failed: ' . $e->getMessage()];
        }
    }

    /** Run RouterOS /ping through the API and return its response rows. */
    public function ping(Router $router, string $address, int $count = 4): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $rows = $client->query(
                (new Query('/ping'))
                    ->equal('address', $address)
                    ->equal('count', (string) max(1, min($count, 10)))
            )->read();
            return ['success' => true, 'message' => "Ping completed for {$address}.", 'rows' => $rows];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Ping failed: ' . $e->getMessage()];
        }
    }
}
