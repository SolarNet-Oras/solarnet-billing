<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\AutomationLog;
use App\Models\DhcpLease;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Router;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
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

    /** Build a compact dashboard monitor from matched DHCP leases and queue snapshots. */
    protected function matchedLeaseMonitor(): array
    {
        $leases = DhcpLease::query()
            ->where('is_matched', true)
            ->whereNotNull('customer_id')
            ->with([
                'customer:id,full_name,status,service_plan_id',
                'customer.servicePlan:id,name,download_speed,upload_speed',
                'router:id,name',
            ])
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

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
            $queueName = 'customer-' . $lease->customer_id;
            $queue = collect($snapshot['data'] ?? [])->firstWhere('name', $queueName);
            $rate = $this->trafficPair($queue['rate'] ?? null);
            $bytes = $this->trafficPair($queue['bytes'] ?? null);

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
                    'download_bps' => $rate[0],
                    'upload_bps' => $rate[1],
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
