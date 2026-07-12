<?php

namespace App\Services\Ai\Tools;

use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\User;
use App\Services\Ai\AiTool;

class SearchByMacOrIpTool implements AiTool
{
    public function name(): string { return 'search_by_mac_or_ip'; }

    public function schema(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'search_by_mac_or_ip',
                'description' => 'Given a MAC address or IP, find which customer (if any) owns it and any matching DHCP lease. Great for questions like "who is 192.168.1.50" or "who owns AA:BB:CC:DD:EE:FF".',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'mac_address' => ['type' => 'string', 'description' => 'MAC address (any case, colon separated).'],
                        'ip_address'  => ['type' => 'string', 'description' => 'IPv4 address.'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasPermission('view-customers') || $user->hasRole('super-admin');
    }

    public function execute(User $user, array $arguments): array
    {
        $mac = isset($arguments['mac_address']) ? strtoupper(trim($arguments['mac_address'])) : null;
        $ip  = isset($arguments['ip_address'])  ? trim($arguments['ip_address'])              : null;
        if (!$mac && !$ip) return ['error' => 'Provide mac_address or ip_address.'];

        // Customer lookup
        $customerQ = Customer::query()->with(['servicePlan:id,name', 'router:id,name']);
        if ($mac) $customerQ->where('mac_address', 'ilike', $mac);
        if ($ip)  $customerQ->orWhere('ip_address', $ip);
        $customer = $customerQ->first();

        // Lease lookup
        $leaseQ = DhcpLease::query()->with('router:id,name');
        if ($mac) $leaseQ->where('mac_address', 'ilike', $mac);
        if ($ip)  $leaseQ->orWhere('ip_address', $ip);
        $lease = $leaseQ->orderBy('last_seen_at', 'desc')->first();

        return [
            'query'    => array_filter(['mac_address' => $mac, 'ip_address' => $ip]),
            'customer' => $customer ? [
                'id'             => $customer->id,
                'account_number' => $customer->account_number,
                'full_name'      => $customer->full_name,
                'status'         => $customer->status,
                'ip_address'     => $customer->ip_address,
                'mac_address'    => $customer->mac_address,
                'service_plan'   => $customer->servicePlan?->name,
                'router'         => $customer->router?->name,
            ] : null,
            'dhcp_lease' => $lease ? [
                'id'          => $lease->id,
                'router'      => $lease->router?->name,
                'mac_address' => $lease->mac_address,
                'ip_address'  => $lease->ip_address,
                'hostname'    => $lease->hostname,
                'comment'     => $lease->comment,
                'is_dynamic'  => (bool) $lease->is_dynamic,
                'is_matched'  => (bool) $lease->is_matched,
                'last_seen'   => optional($lease->last_seen_at)->toIso8601String(),
            ] : null,
        ];
    }
}
