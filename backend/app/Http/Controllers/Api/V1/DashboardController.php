<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\AutomationLog;
use App\Models\DhcpLease;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Router;
use App\Models\ServicePlan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\MikrotikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /** Technicians can reference all client locations; only their tickets are assigned work. */
    public function technicianWorkspace(Request $request): JsonResponse
    {
        $technicianId = $request->user()->id;
        $clients = Customer::query()
            ->whereNotNull('gps_coordinates')
            ->orderBy('full_name')
            ->get(['id', 'account_number', 'full_name', 'address', 'status', 'gps_coordinates']);
        $tickets = Ticket::with([
            'customer:id,account_number,full_name,address,notes,gps_coordinates,service_plan_id',
            'customer.servicePlan:id,name,download_speed,upload_speed,price',
            'assignedTechnician:id,name,email',
            'histories.user:id,name,email',
        ])
            ->where(function ($query) use ($technicianId) {
                $query->where('assigned_to', $technicianId)
                    ->orWhere(function ($available) {
                        $available->whereNull('assigned_to')
                            ->whereIn('ticket_type', ['installation', 'repair'])
                            ->whereIn('workflow_status', ['unclaimed', 'open']);
                    });
            })
            ->latest('updated_at')
            ->get([
                'id', 'ticket_number', 'customer_id', 'assigned_to', 'subject', 'description',
                'status', 'priority', 'category', 'ticket_type', 'workflow_status',
                'claimed_at', 'started_at', 'resolution_notes', 'repair_details',
                'installation_mac', 'installation_notes', 'submitted_for_approval_at',
                'approved_at', 'return_reason', 'returned_at', 'registered_at',
                'resolved_at', 'closed_at', 'created_at', 'updated_at',
            ]);
        $tickets->each(function (Ticket $ticket): void {
            // Keep signup notes available as a stable top-level field for field clients.
            $ticket->setAttribute('client_notes', $ticket->customer?->notes);
        });

        $servicePlans = ServicePlan::query()
            ->where('is_active', true)
            ->whereRaw("LOWER(name) NOT LIKE '%company owned%'")
            ->orderBy('price')
            ->get(['id', 'name', 'download_speed', 'upload_speed', 'price']);

        $pendingRegistrations = Customer::query()
            ->where('status', 'pending')
            ->where('mac_binding_status', 'waiting_for_match')
            ->with('servicePlan:id,name,download_speed,upload_speed,price')
            ->latest('created_at')
            ->get([
                'id', 'account_number', 'full_name', 'address', 'contact_number',
                'email', 'mac_address', 'installation_date', 'service_plan_id',
                'mac_binding_status', 'created_at',
            ]);

        return response()->json([
            'clients' => $clients,
            'tickets' => $tickets,
            'service_plans' => $servicePlans,
            'pending_registrations' => $pendingRegistrations,
        ]);
    }

    public function technicianMonitor(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->matchedLeaseMonitor(), 'refreshed_at' => now()->toIso8601String()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
    /**
     * Get dashboard metrics — all values come from real DB queries.
     */
    public function metrics(): JsonResponse
    {
        $today       = Carbon::today();
        $monthStart  = Carbon::now()->startOfMonth();
        $prevMonth   = Carbon::now()->subMonthNoOverflow();
        $prevMonthS  = $prevMonth->copy()->startOfMonth();
        $prevMonthE  = $prevMonth->copy()->endOfMonth();

        // ---- Subscribers ----
        $totalSubs    = Customer::count();
        $activeSubs   = Customer::where('status', 'active')->count();
        $suspended    = Customer::where('status', 'suspended')->count();
        $expiredSubs  = Customer::where('status', 'expired')->count();

        // Growth vs previous month
        $subsThisMonth = Customer::where('created_at', '>=', $monthStart)->count();
        $subsLastMonth = Customer::whereBetween('created_at', [$prevMonthS, $prevMonthE])->count();
        $subsChangePct = $this->percentChange($subsLastMonth, $subsThisMonth);

        // ---- Revenue (from Payments) ----
        $todayRevenue   = (float) Payment::whereDate('payment_date', $today)->sum('amount');
        $monthRevenue   = (float) Payment::where('payment_date', '>=', $monthStart)->sum('amount');
        $prevMonthRev   = (float) Payment::whereBetween('payment_date', [$prevMonthS, $prevMonthE])->sum('amount');
        $revChangePct   = $this->percentChange($prevMonthRev, $monthRevenue);

        // ---- Invoices ----
        $pendingPayments = Invoice::whereIn('status', ['sent', 'partial'])->where('balance', '>', 0)->count();
        $overdueInvoices = Invoice::where('due_date', '<', $today)->where('balance', '>', 0)
                                  ->whereIn('status', ['sent', 'partial', 'overdue'])->count();
        $paidInvoices    = Invoice::where('status', 'paid')->count();
        $partialInvoices = Invoice::where('status', 'partial')->count();
        $unpaidInvoices  = Invoice::whereIn('status', ['sent', 'overdue'])
            ->where('balance', '>', 0)
            ->count();
        $totalBilled     = (float) Invoice::whereNotIn('status', ['draft', 'cancelled'])->sum('total');
        $totalPaid       = (float) Invoice::whereNotIn('status', ['draft', 'cancelled'])->sum('paid_amount');
        $partialPaid     = (float) Invoice::where('status', 'partial')->sum('paid_amount');
        $collectible     = (float) Invoice::whereNotIn('status', ['draft', 'cancelled'])
            ->where('balance', '>', 0)
            ->sum('balance');
        $collectionRate  = $totalBilled > 0 ? round(($totalPaid / $totalBilled) * 100, 1) : 0.0;

        // ---- Tickets ----
        $openTickets    = Ticket::where('status', 'open')->count();
        $pendingTickets = Ticket::where('status', 'pending')->count();
        $resolvedToday  = Ticket::whereDate('resolved_at', $today)->count();

        // ---- Routers ----
        $routerOnline  = Router::where('connection_status', 'online')->count();
        $routerOffline = Router::where('connection_status', 'offline')->count();
        $routerError   = Router::where('connection_status', 'error')->count();
        $routerTotal   = Router::count();

        // ---- Users / connectivity ----
        $usersOnline = User::whereNotNull('last_login_at')
            ->where('last_login_at', '>=', Carbon::now()->subMinutes(15))
            ->count();

        // Online subscribers = active DHCP leases (best available proxy)
        $onlineUsers = $this->activeDhcpLeaseCount();

        return response()->json([
            'status' => 'success',
            'data' => [
                // Subscribers
                'total_subscribers'      => $totalSubs,
                'active_subscribers'     => $activeSubs,
                'suspended_subscribers'  => $suspended,
                'expired_subscribers'    => $expiredSubs,
                'subscribers_change_pct' => $subsChangePct,

                // Connectivity
                'online_users'   => $onlineUsers,
                'offline_users'  => max(0, $activeSubs - $onlineUsers),

                // Financials
                'today_revenue'         => round($todayRevenue, 2),
                'monthly_revenue'       => round($monthRevenue, 2),
                'revenue_change_pct'    => $revChangePct,
                'pending_payments'      => $pendingPayments,
                'overdue_invoices'      => $overdueInvoices,
                'paid_invoices'         => $paidInvoices,
                'partial_invoices'      => $partialInvoices,
                'unpaid_invoices'       => $unpaidInvoices,
                'total_billed'          => round($totalBilled, 2),
                'total_paid'            => round($totalPaid, 2),
                'partial_paid'          => round($partialPaid, 2),
                'collectible'           => round($collectible, 2),
                'collection_rate'       => $collectionRate,

                // Tickets
                'open_tickets'    => $openTickets,
                'pending_tickets' => $pendingTickets,
                'resolved_today'  => $resolvedToday,

                // Routers
                'router_status' => [
                    'online'  => $routerOnline,
                    'offline' => $routerOffline,
                    'error'   => $routerError,
                    'total'   => $routerTotal,
                ],

                // System
                'total_users'   => User::count(),
                'active_users'  => User::where('is_active', true)->count(),
                'users_online'  => $usersOnline,

                // Recent activity (real rows only)
                'recent_signups' => User::orderBy('created_at', 'desc')
                    ->limit(5)->get(['id', 'name', 'email', 'created_at']),
                'recent_logins' => User::whereNotNull('last_login_at')
                    ->orderBy('last_login_at', 'desc')
                    ->limit(5)->get(['id', 'name', 'email', 'last_login_at']),
                'automation_activity' => AutomationLog::orderByDesc('created_at')
                    ->limit(6)
                    ->get(['id', 'job', 'status', 'summary', 'finished_at']),
                // Client monitor is based on matched DHCP leases and simple
                // queue snapshots, not OLT/ONU data. Queue snapshots are
                // updated during Router Sync and remain safe to display while
                // a router VPN/API connection is temporarily unavailable.
                'client_monitor' => $this->matchedLeaseMonitor(),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Refresh and return only the compact client traffic monitor.
     *
     * This deliberately avoids recalculating the rest of the dashboard every
     * five seconds. A short backoff keeps a broken VPN/API link from being
     * retried by every browser refresh.
     */
    public function clientMonitor(MikrotikService $mikrotikService): JsonResponse
    {
        $routerIds = DhcpLease::query()
            ->where('is_matched', true)
            ->whereNotNull('customer_id')
            ->whereNotNull('router_id')
            ->presentOnRouter()
            ->active()
            ->distinct()
            ->pluck('router_id');

        Router::query()
            ->whereIn('id', $routerIds)
            ->where('is_active', true)
            ->where('connection_status', 'online')
            ->get()
            ->each(function (Router $router) use ($mikrotikService): void {
                $backoffKey = "router:queues:retry-after:{$router->id}";
                $lockKey = "router:queues:refresh-lock:{$router->id}";

                if (Cache::has($backoffKey) || !Cache::add($lockKey, true, now()->addSeconds(10))) {
                    return;
                }

                try {
                    $result = $mikrotikService->getQueues($router);
                    if ($result['success']) {
                        Cache::forget($backoffKey);
                    } else {
                        // Keep the most recent good queue counters visible while
                        // preventing repeated socket timeouts from overwhelming
                        // the dashboard or the router.
                        Cache::put($backoffKey, true, now()->addSeconds(30));
                    }
                } finally {
                    Cache::forget($lockKey);
                }
            });

        return response()->json([
            'status' => 'success',
            'data' => $this->matchedLeaseMonitor(),
            'refreshed_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
          ->header('Pragma', 'no-cache');
    }

    /** Build a compact dashboard monitor from matched DHCP leases and queue snapshots. */
    protected function matchedLeaseMonitor(?string $technicianId = null): array
    {
        $query = DhcpLease::query()
            ->where('is_matched', true)
            ->whereNotNull('customer_id')
            ->presentOnRouter()
            ->active()
            ->with([
                'customer:id,full_name,status,service_plan_id',
                'customer.servicePlan:id,name,download_speed,upload_speed',
                'router:id,name',
            ])
            ->orderByDesc('last_seen_at');
        if ($technicianId) $query->whereHas('customer', fn ($customers) => $customers->where('technician_id', $technicianId));
        $leases = $query->limit(50)->get();

        $queueSnapshots = [];
        $monitor = [];
        foreach ($leases as $lease) {
            if (!$lease->customer || isset($monitor[$lease->customer_id])) {
                continue;
            }

            $routerId = (string) $lease->router_id;
            if (!array_key_exists($routerId, $queueSnapshots)) {
                $queueSnapshots[$routerId] = Cache::get("router:queues:{$routerId}", ['data' => [], 'captured_at' => null]);
            }
            $snapshot = $queueSnapshots[$routerId];
            $queue = $this->activeQueueForLease($snapshot['data'] ?? [], $lease->customer_id, $lease->ip_address);
            $queueName = $queue['name'] ?? ('customer-' . $lease->customer_id);
            $rate = $this->trafficPair($queue['rate'] ?? null);
            $bytes = $this->trafficPair($queue['bytes'] ?? null);
            $previousQueue = collect($snapshot['previous_data'] ?? [])->firstWhere('name', $queueName);
            $derivedRate = $this->counterRate(
                $bytes,
                $this->trafficPair($previousQueue['bytes'] ?? null),
                $snapshot['captured_at'] ?? null,
                $snapshot['previous_captured_at'] ?? null,
            );
            // Some RouterOS versions return 0/0 even while bytes increase.
            // Prefer its rate when non-zero; otherwise use the measured bytes
            // across the most recent two successful five-second polls.
            $displayRate = [
                ($rate[0] ?? 0) > 0 ? $rate[0] : $derivedRate[0],
                ($rate[1] ?? 0) > 0 ? $rate[1] : $derivedRate[1],
            ];

            $monitor[$lease->customer_id] = [
                'customer_id' => $lease->customer_id,
                'full_name' => $lease->customer->full_name,
                'customer_status' => $lease->customer->status,
                'ip_address' => $lease->ip_address,
                'lease_status' => $lease->status,
                'last_seen_at' => $lease->last_seen_at?->toIso8601String(),
                'router_name' => $lease->router?->name,
                'queue_name' => $queueName,
                'queue_found' => $queue !== null,
                'queue_snapshot_at' => $snapshot['captured_at'] ?? null,
                'traffic' => [
                    // RouterOS may provide live rate; counters are retained
                    // even on RouterOS versions that omit that field.
                    'download_bps' => $displayRate[0],
                    'upload_bps' => $displayRate[1],
                    'download_bytes' => $bytes[0],
                    'upload_bytes' => $bytes[1],
                ],
                'service_plan' => $lease->customer->servicePlan ? [
                    'name' => $lease->customer->servicePlan->name,
                    'download_speed' => $lease->customer->servicePlan->download_speed,
                    'upload_speed' => $lease->customer->servicePlan->upload_speed,
                ] : null,
            ];

            if (count($monitor) >= 6) {
                break;
            }
        }

        return array_values($monitor);
    }

    /**
     * A MikroTik DHCP lease rate-limit creates a dynamic `dhcp-ds<...>` simple
     * queue. When it coexists with our old `customer-...` queue, RouterOS
     * charges traffic to the dynamic queue and the old queue remains at 0/0.
     * Select by the lease target, preferring a queue that has live/cumulative
     * traffic, so the monitor shows the queue actually handling the service.
     */
    protected function activeQueueForLease(array $queues, string $customerId, ?string $ipAddress): ?array
    {
        $target = $ipAddress ? $ipAddress . '/32' : null;
        $candidates = collect($queues)->filter(function (array $queue) use ($customerId, $target): bool {
            return ($queue['name'] ?? null) === 'customer-' . $customerId
                || ($target && ($queue['target'] ?? null) === $target);
        });

        return $candidates->sortByDesc(function (array $queue): int {
            $rate = $this->trafficPair($queue['rate'] ?? null);
            $bytes = $this->trafficPair($queue['bytes'] ?? null);
            return array_sum(array_map(static fn ($value) => (int) ($value ?? 0), $rate)) * 1000000000000
                + array_sum(array_map(static fn ($value) => (int) ($value ?? 0), $bytes));
        })->first();
    }

    /** Parse RouterOS traffic pairs such as "12345/67890". */
    protected function trafficPair(?string $value): array
    {
        if (!$value || !str_contains($value, '/')) {
            return [null, null];
        }

        [$first, $second] = array_pad(explode('/', $value, 2), 2, null);
        return [
            is_numeric($first) ? (int) $first : null,
            is_numeric($second) ? (int) $second : null,
        ];
    }

    /** Derive bits/sec from monotonic RouterOS byte counters. */
    protected function counterRate(array $current, array $previous, ?string $currentAt, ?string $previousAt): array
    {
        if (!$currentAt || !$previousAt || in_array(null, $current, true) || in_array(null, $previous, true)) return [null, null];
        $seconds = Carbon::parse($previousAt)->diffInRealSeconds(Carbon::parse($currentAt));
        if ($seconds <= 0 || $seconds > 60) return [null, null];
        return array_map(static fn ($now, $before) => $now >= $before ? (int) round((($now - $before) * 8) / $seconds) : null, $current, $previous);
    }

    /**
     * Quick stats widgets — every value computed from live DB.
     */
    public function quickStats(): JsonResponse
    {
        $monthStart = Carbon::now()->startOfMonth();
        $prevStart  = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $prevEnd    = Carbon::now()->subMonthNoOverflow()->endOfMonth();

        $customersThisMonth = Customer::where('created_at', '>=', $monthStart)->count();
        $customersLastMonth = Customer::whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $customerChange     = $this->percentChange($customersLastMonth, $customersThisMonth);

        $revenueThisMonth = (float) Payment::where('payment_date', '>=', $monthStart)->sum('amount');
        $revenueLastMonth = (float) Payment::whereBetween('payment_date', [$prevStart, $prevEnd])->sum('amount');
        $revenueChange    = $this->percentChange($revenueLastMonth, $revenueThisMonth);

        $activeSubs = Customer::where('status', 'active')->count();
        $activeSubsPrev = Customer::where('status', 'active')
            ->where('created_at', '<', $monthStart)->count();
        $activeSubsChange = $this->percentChange($activeSubsPrev, $activeSubs);

        $onlineRouters = Router::where('connection_status', 'online')->count();
        $totalRouters  = Router::count();
        $routerUptimePct = $totalRouters > 0
            ? round(($onlineRouters / $totalRouters) * 100, 1)
            : 0.0;

        $stats = [
            [
                'label' => 'Total Customers',
                'value' => Customer::count(),
                'change' => $this->formatChange($customerChange),
                'trend'  => $this->trend($customerChange),
                'icon'   => 'users',
            ],
            [
                'label' => 'Active Subscribers',
                'value' => $activeSubs,
                'change' => $this->formatChange($activeSubsChange),
                'trend'  => $this->trend($activeSubsChange),
                'icon'   => 'activity',
            ],
            [
                'label' => 'Monthly Revenue',
                'value' => '₱' . number_format($revenueThisMonth, 2),
                'change' => $this->formatChange($revenueChange),
                'trend'  => $this->trend($revenueChange),
                'icon'   => 'dollar',
            ],
            [
                'label' => 'Router Uptime',
                'value' => $routerUptimePct . '%',
                'change' => "$onlineRouters / $totalRouters online",
                'trend'  => $routerUptimePct >= 95 ? 'up' : ($routerUptimePct >= 80 ? 'stable' : 'down'),
                'icon'   => 'heart',
            ],
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats,
        ]);
    }

    /** ------------ helpers ------------ */

    /** Percent change from $prev to $curr. Returns null if not meaningful. */
    protected function percentChange(float $prev, float $curr): ?float
    {
        if ($prev == 0.0) {
            return $curr > 0 ? 100.0 : null;
        }
        return round((($curr - $prev) / $prev) * 100, 1);
    }

    protected function formatChange(?float $pct): string
    {
        if ($pct === null) return 'No prior data';
        $sign = $pct > 0 ? '+' : '';
        return "{$sign}{$pct}% vs last month";
    }

    protected function trend(?float $pct): string
    {
        if ($pct === null || abs($pct) < 0.1) return 'stable';
        return $pct > 0 ? 'up' : 'down';
    }

    /** Count DHCP leases seen in the last 5 minutes. Returns 0 if table missing. */
    protected function activeDhcpLeaseCount(): int
    {
        try {
            return \App\Models\DhcpLease::where('last_seen_at', '>=', now()->subMinutes(5))
                ->orWhere('status', 'active')
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
