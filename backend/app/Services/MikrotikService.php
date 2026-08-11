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
}
