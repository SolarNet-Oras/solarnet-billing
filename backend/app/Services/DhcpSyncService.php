<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\Router;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DhcpSyncService
{
    protected MikrotikService $mikrotikService;
    protected QueueService $queueService;

    public function __construct(MikrotikService $mikrotikService, QueueService $queueService)
    {
        $this->mikrotikService = $mikrotikService;
        $this->queueService = $queueService;
    }

    /**
     * Sync DHCP leases from a specific router
     * 
     * @param Router $router
     * @param bool $autoCreateCustomers
     * @return array
     */
    public function syncRouterLeases(Router $router, bool $autoCreateCustomers = false): array
    {
        $result = [
            'router' => $router->name,
            'leases_fetched' => 0,
            'leases_stored' => 0,
            'customers_matched' => 0,
            'customers_created' => 0,
            'ips_updated' => 0,
            'queues_synced' => 0,
            'errors' => [],
        ];

        try {
            // Fetch leases from MikroTik
            $leasesResponse = $this->mikrotikService->getDhcpLeasesDetailed($router);
            
            if (!$leasesResponse['success']) {
                $result['errors'][] = $leasesResponse['message'];
                return $result;
            }

            $leases = $leasesResponse['data'];
            $result['leases_fetched'] = count($leases);

            foreach ($leases as $leaseData) {
                // Skip invalid leases
                if (empty($leaseData['mac_address']) || empty($leaseData['ip_address'])) {
                    continue;
                }

                // Store or update lease
                $lease = $this->storeLease($router, $leaseData);
                if ($lease) {
                    $result['leases_stored']++;
                }

                // Match to existing customer by MAC
                $customer = $this->matchLeaseToCustomer($lease);
                
                if ($customer) {
                    $result['customers_matched']++;
                    
                    // Update customer IP if changed
                    if ($customer->ip_address !== $lease->ip_address) {
                        $customer->update(['ip_address' => $lease->ip_address]);
                        $result['ips_updated']++;
                        
                        // Trigger queue sync (observer will handle this)
                        $result['queues_synced']++;
                    }
                } elseif ($autoCreateCustomers && $leaseData['status'] === 'bound') {
                    // Auto-create customer from unknown MAC
                    $newCustomer = $this->autoCreateCustomer($router, $lease, $leaseData);
                    if ($newCustomer) {
                        $result['customers_created']++;
                    }
                }
            }

            Log::info('DHCP sync completed for router', $result);
            
            return $result;

        } catch (\Exception $e) {
            Log::error('DHCP sync failed', [
                'router' => $router->name,
                'error' => $e->getMessage(),
            ]);
            
            $result['errors'][] = $e->getMessage();
            return $result;
        }
    }

    /**
     * Sync leases from all routers
     * 
     * @param bool $autoCreateCustomers
     * @return array
     */
    public function syncAllRouters(bool $autoCreateCustomers = false): array
    {
        $routers = Router::where('is_active', true)
                        ->where('connection_status', 'online')
                        ->get();

        $results = [
            'total_routers' => $routers->count(),
            'success' => 0,
            'failed' => 0,
            'routers' => [],
        ];

        foreach ($routers as $router) {
            $result = $this->syncRouterLeases($router, $autoCreateCustomers);
            
            if (empty($result['errors'])) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            
            $results['routers'][] = $result;
        }

        return $results;
    }

    /**
     * Store or update DHCP lease
     * 
     * @param Router $router
     * @param array $leaseData
     * @return DhcpLease|null
     */
    protected function storeLease(Router $router, array $leaseData): ?DhcpLease
    {
        try {
            $expiresAt = null;
            if (!empty($leaseData['expires_after'])) {
                // Parse MikroTik time format (e.g., "1d2h3m4s")
                $expiresAt = $this->parseMikrotikTime($leaseData['expires_after']);
            }

            $lease = DhcpLease::updateOrCreate(
                [
                    'router_id' => $router->id,
                    'mac_address' => $leaseData['mac_address'],
                ],
                [
                    'ip_address'   => $leaseData['ip_address'],
                    'hostname'     => $leaseData['hostname'] ?? null,
                    'comment'      => $leaseData['comment'] ?? null,
                    'rate_limit'   => $leaseData['rate_limit'] ?? null,
                    'is_dynamic'   => $leaseData['is_dynamic'] ?? true,
                    'status'       => $leaseData['status'] ?? 'unknown',
                    'server'       => $leaseData['server'] ?? 'default',
                    'expires_at'   => $expiresAt,
                    'last_seen_at' => now(),
                ]
            );

            return $lease;

        } catch (\Exception $e) {
            Log::error('Failed to store DHCP lease', [
                'mac' => $leaseData['mac_address'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Match lease to existing customer by MAC address
     * 
     * @param DhcpLease $lease
     * @return Customer|null
     */
    protected function matchLeaseToCustomer(DhcpLease $lease): ?Customer
    {
        if (!$lease->mac_address) {
            return null;
        }

        $customer = Customer::where('mac_address', $lease->mac_address)->first();

        if ($customer) {
            // Update lease with customer match
            $lease->update([
                'customer_id' => $customer->id,
                'is_matched' => true,
            ]);

            return $customer;
        }

        return null;
    }

    /**
     * Auto-create customer from DHCP lease
     * 
     * @param Router $router
     * @param DhcpLease $lease
     * @param array $leaseData
     * @return Customer|null
     */
    protected function autoCreateCustomer(Router $router, DhcpLease $lease, array $leaseData): ?Customer
    {
        try {
            $accountNumber = 'AUTO-' . strtoupper(substr(md5($lease->mac_address), 0, 8));
            $fullName = $leaseData['hostname'] ?? 'Auto Customer ' . substr($lease->mac_address, -8);

            $customer = Customer::create([
                'account_number' => $accountNumber,
                'full_name' => $fullName,
                'contact_number' => 'N/A',
                'address' => 'Auto-generated from DHCP',
                'email' => strtolower(str_replace([':', '-'], '', $lease->mac_address)) . '@auto.local',
                'mac_address' => $lease->mac_address,
                'ip_address' => $lease->ip_address,
                'router_id' => $router->id,
                'status' => 'pending', // Requires admin review
                'installation_date' => now(),
                'monthly_fee' => 0,
                'notes' => 'Auto-created from DHCP lease on ' . now()->format('Y-m-d H:i:s'),
            ]);

            // Link lease to customer
            $lease->update([
                'customer_id' => $customer->id,
                'is_matched' => true,
            ]);

            Log::info('Auto-created customer from DHCP lease', [
                'customer_id' => $customer->id,
                'account_number' => $accountNumber,
                'mac' => $lease->mac_address,
                'ip' => $lease->ip_address,
            ]);

            return $customer;

        } catch (\Exception $e) {
            Log::error('Failed to auto-create customer from DHCP', [
                'mac' => $lease->mac_address,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse MikroTik time format to Carbon timestamp
     * 
     * @param string $timeStr (e.g., "1d2h3m4s" or "23h59m")
     * @return Carbon
     */
    protected function parseMikrotikTime(string $timeStr): Carbon
    {
        $now = Carbon::now();
        
        // Extract days, hours, minutes, seconds
        preg_match('/(\d+)d/', $timeStr, $days);
        preg_match('/(\d+)h/', $timeStr, $hours);
        preg_match('/(\d+)m/', $timeStr, $minutes);
        preg_match('/(\d+)s/', $timeStr, $seconds);

        if (!empty($days[1])) $now->addDays((int)$days[1]);
        if (!empty($hours[1])) $now->addHours((int)$hours[1]);
        if (!empty($minutes[1])) $now->addMinutes((int)$minutes[1]);
        if (!empty($seconds[1])) $now->addSeconds((int)$seconds[1]);

        return $now;
    }

    /**
     * Get unmatched leases (no customer)
     * 
     * @param Router|null $router
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUnmatchedLeases(?Router $router = null)
    {
        $query = DhcpLease::with(['router'])
                          ->unmatched()
                          ->active()
                          ->orderBy('last_seen_at', 'desc');

        if ($router) {
            $query->where('router_id', $router->id);
        }

        return $query->get();
    }
}
