<?php

namespace App\Services\Ai\Tools;

use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\Router;
use App\Models\User;
use App\Services\Ai\AiTool;
use Carbon\Carbon;

/**
 * Snapshot of the network + billing state — used as the "dashboard summary"
 * the AI opens conversations with.
 */
class GetNetworkStatusTool implements AiTool
{
    public function name(): string { return 'get_network_status'; }

    public function schema(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_network_status',
                'description' => 'Return a high-level snapshot of the ISP network: active customer counts, invoices due today, router connection status, and DHCP lease totals. Call this to answer questions like "how is the network doing" or "network summary".',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => (object) [],
                    'required'   => [],
                ],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return true; // Everyone with AI access can see the summary
    }

    public function execute(User $user, array $arguments): array
    {
        $now = Carbon::now();

        $activeCustomers    = Customer::where('status', 'active')->count();
        $suspendedCustomers = Customer::where('status', 'suspended')->count();
        $totalCustomers     = Customer::count();

        $dueToday = 0;
        if (class_exists(\App\Models\Invoice::class)) {
            $dueToday = \App\Models\Invoice::whereDate('due_date', $now->toDateString())
                ->whereIn('status', ['pending', 'overdue', 'partial'])
                ->count();
        }

        $routersTotal   = Router::count();
        $routersOnline  = Router::where('connection_status', 'online')->count();
        $routersOffline = Router::whereIn('connection_status', ['offline', 'unknown', ''])->count();

        $leasesTotal      = DhcpLease::count();
        $leasesUnmatched  = DhcpLease::where('is_matched', false)->count();
        $staticCommented  = DhcpLease::where('is_matched', false)->where('is_dynamic', false)
                                ->whereNotNull('comment')->where('comment', '!=', '')->count();

        return [
            'as_of'        => $now->toIso8601String(),
            'customers'    => [
                'active'    => $activeCustomers,
                'suspended' => $suspendedCustomers,
                'total'     => $totalCustomers,
            ],
            'invoices'     => [
                'due_today' => $dueToday,
            ],
            'routers'      => [
                'online'  => $routersOnline,
                'offline' => $routersOffline,
                'total'   => $routersTotal,
            ],
            'dhcp_leases'  => [
                'total'                     => $leasesTotal,
                'unregistered'              => $leasesUnmatched,
                'ready_for_quick_register'  => $staticCommented,
            ],
        ];
    }
}
