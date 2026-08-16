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
            'cross_router_matches_detached' => 0,
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
            $seenMacAddresses = [];

            foreach ($leases as $leaseData) {
                // Skip invalid leases
                if (empty($leaseData['mac_address']) || empty($leaseData['ip_address'])) {
                    continue;
                }

                $seenMacAddresses[] = $this->normalizeMacAddress($leaseData['mac_address']);

                // Store or update lease
                $lease = $this->storeLease($router, $leaseData);
                if (!$lease) {
                    continue;
                }
                $result['leases_stored']++;

                // Only a currently bound lease can become a live client. A
                // waiting/expired lease must never update a customer's IP or
                // appear as a live connection.
                $customer = strtolower((string) ($leaseData['status'] ?? '')) === 'bound'
                    ? $this->matchLeaseToCustomer($lease)
                    : null;
                
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

            // The API response is the source of truth after a successful
            // router sync. Keep historical rows, but mark entries that the
            // router no longer returned as not current so they cannot become
            // ghost clients in the dashboard or unregistered-lease lists.
            $staleLeases = DhcpLease::query()->where('router_id', $router->id);
            if ($seenMacAddresses !== []) {
                $staleLeases->whereNotIn(DB::raw('upper(mac_address)'), array_values(array_unique($seenMacAddresses)));
            }
            $staleLeases->update(['is_current' => false]);

            // Repair records created by older versions that matched only on
            // MAC address. A lease may remain on the other router as an
            // unregistered lease, but it must not remain attached to the
            // wrong customer's account.
            $result['cross_router_matches_detached'] = $this->detachCrossRouterMatches($router);

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

            $macAddress = $this->normalizeMacAddress($leaseData['mac_address']);
            $lease = DhcpLease::query()
                ->where('router_id', $router->id)
                ->whereRaw('upper(mac_address) = ?', [$macAddress])
                ->first();

            if (!$lease) {
                $lease = new DhcpLease([
                    'router_id' => $router->id,
                    'mac_address' => $macAddress,
                ]);
            }

            $lease->fill([
                'ip_address'   => $leaseData['ip_address'],
                'hostname'     => $leaseData['hostname'] ?? null,
                'comment'      => $leaseData['comment'] ?? null,
                'rate_limit'   => $leaseData['rate_limit'] ?? null,
                'is_dynamic'   => $leaseData['is_dynamic'] ?? true,
                'status'       => $leaseData['status'] ?? 'unknown',
                'server'       => $leaseData['server'] ?? 'default',
                'expires_at'   => $expiresAt,
                'last_seen_at' => now(),
                'is_current'   => true,
            ]);
            $lease->save();

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
     * Match a read-only DHCP lease to a customer. An exact registered MAC is
     * preferred. A unique, exact account number in the lease comment is a
     * safe fallback only when it does not conflict with a registered MAC.
     * This writes SolarNet's database association only; it never writes to
     * RouterOS DHCP, pools, VLANs, NAT, firewall, QoS, or routing.
     */
    protected function matchLeaseToCustomer(DhcpLease $lease): ?Customer
    {
        if (!$lease->mac_address) {
            return null;
        }

        $customer = Customer::query()
            ->whereRaw('upper(mac_address) = ?', [$this->normalizeMacAddress($lease->mac_address)])
            // A customer assigned to Router A must never be claimed by a
            // lease from Router B, even if both networks reuse a MAC/IP.
            ->where(function ($query) use ($lease) {
                $query->where('router_id', $lease->router_id)
                    ->orWhereNull('router_id');
            })
            ->orderByRaw('case when router_id = ? then 0 else 1 end', [$lease->router_id])
            ->first();

        if ($customer) {
            if (!$customer->router_id) {
                $customer->update(['router_id' => $lease->router_id]);
            }
            // Update lease with customer match
            $lease->update([
                'customer_id' => $customer->id,
                'is_matched' => true,
                'match_source' => 'mac_address',
                'match_note' => 'Exact registered MAC address and router match.',
            ]);

            return $customer;
        }

        $accountNumbers = $this->accountNumbersFromLeaseComment($lease->comment);
        if ($accountNumbers === []) {
            $this->markLeaseUnmatched($lease, 'No exact registered MAC or account number was found.');
            return null;
        }

        $candidates = Customer::query()
            ->whereIn('account_number', $accountNumbers)
            ->where(function ($query) use ($lease) {
                $query->where('router_id', $lease->router_id)
                    ->orWhereNull('router_id');
            })
            ->orderByRaw('case when router_id = ? then 0 else 1 end', [$lease->router_id])
            ->get();

        if ($candidates->count() !== 1) {
            $this->markLeaseUnmatched(
                $lease,
                $candidates->isEmpty()
                    ? 'Lease comment contains no customer account assigned to this router.'
                    : 'Lease comment maps to multiple eligible customer accounts. Staff review is required.',
            );
            return null;
        }

        $customer = $candidates->first();
        $leaseMac = $this->normalizeMacAddress($lease->mac_address);
        $customerMac = $this->normalizeMacAddress($customer->mac_address);
        if ($customerMac && $customerMac !== $leaseMac) {
            $this->markLeaseUnmatched(
                $lease,
                'Lease comment matches an account, but its registered MAC is different. No automatic reassignment was made.',
            );
            return null;
        }

        $customerUpdates = [];
        if (!$customer->router_id) {
            $customerUpdates['router_id'] = $lease->router_id;
        }
        if (!$customerMac) {
            // The exact account comment is an administrator-created RouterOS
            // association, so a missing app-side MAC can be recorded safely.
            $customerUpdates['mac_address'] = $leaseMac;
        }
        if ($customerUpdates !== []) {
            $customer->update($customerUpdates);
        }

        $lease->update([
            'customer_id' => $customer->id,
            'is_matched' => true,
            'match_source' => 'account_comment',
            'match_note' => 'Exact account number in RouterOS lease comment matched one eligible customer.',
        ]);

        return $customer;
    }

    /** @return array<int, string> */
    protected function accountNumbersFromLeaseComment(?string $comment): array
    {
        $comment = trim((string) $comment);
        if ($comment === '') return [];

        preg_match_all('/(?<![A-Z0-9_-])(?:CUST-[A-Z0-9-]+|PENDING-[A-Z0-9-]+|[0-9]{6,})(?![A-Z0-9_-])/i', $comment, $matches);

        return array_values(array_unique(array_map(
            fn (string $value) => strtoupper(trim($value)),
            $matches[0] ?? [],
        )));
    }

    protected function markLeaseUnmatched(DhcpLease $lease, string $note): void
    {
        $lease->update([
            'customer_id' => null,
            'is_matched' => false,
            'match_source' => null,
            'match_note' => $note,
        ]);
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

    protected function normalizeMacAddress(string $macAddress): string
    {
        return strtoupper(trim($macAddress));
    }

    protected function detachCrossRouterMatches(Router $router): int
    {
        // Use a database join rather than hydrated model relations. This also
        // repairs records written by older releases even if their relation is
        // affected by a legacy scope or stale relation cache.
        $leaseIds = DB::table('dhcp_leases as lease')
            ->join('customers as customer', 'customer.id', '=', 'lease.customer_id')
            ->where('lease.router_id', $router->id)
            ->where('lease.is_current', true)
            ->whereNotNull('customer.router_id')
            ->whereColumn('lease.router_id', '<>', 'customer.router_id')
            ->pluck('lease.id');

        if ($leaseIds->isEmpty()) {
            return 0;
        }

        DhcpLease::query()
            ->whereIn('id', $leaseIds)
            ->update([
                'customer_id' => null,
                'is_matched' => false,
            ]);

        Log::warning('Detached cross-router DHCP lease matches', [
            'lease_router_id' => $router->id,
            'lease_ids' => $leaseIds->values()->all(),
        ]);

        return $leaseIds->count();
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
                          ->presentOnRouter()
                          ->orderBy('last_seen_at', 'desc');

        if ($router) {
            $query->where('router_id', $router->id);
        }

        return $query->get();
    }
}
