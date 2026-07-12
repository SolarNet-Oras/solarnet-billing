<?php

namespace App\Services\Ai\Tools;

use App\Models\DhcpLease;
use App\Models\User;
use App\Services\Ai\AiTool;

class ListUnregisteredLeasesTool implements AiTool
{
    public function name(): string { return 'list_unregistered_leases'; }

    public function schema(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'list_unregistered_leases',
                'description' => 'List DHCP leases synced from MikroTik that are NOT yet registered as customers. Use for questions like "who is on the network but not a customer?" or "how many pending sign-ups?".',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'variant' => [
                            'type'        => 'string',
                            'enum'        => ['static_commented', 'dynamic', 'all'],
                            'description' => 'static_commented = ready for 1-click register (MikroTik comment set, not dynamic). dynamic = requires manual add. all = both.',
                        ],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
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
        $variant = $arguments['variant'] ?? 'all';
        $limit   = max(1, min(50, (int) ($arguments['limit'] ?? 20)));

        $q = DhcpLease::query()->with('router:id,name')->where('is_matched', false);
        if ($variant === 'static_commented') {
            $q->where('is_dynamic', false)->whereNotNull('comment')->where('comment', '!=', '');
        } elseif ($variant === 'dynamic') {
            $q->where(fn ($w) => $w->where('is_dynamic', true)->orWhereNull('comment')->orWhere('comment', ''));
        }
        $leases = $q->orderBy('last_seen_at', 'desc')->limit($limit)->get();

        return [
            'variant' => $variant,
            'count'   => $leases->count(),
            'rows'    => $leases->map(fn ($l) => [
                'id'          => $l->id,
                'router'      => $l->router?->name,
                'mac_address' => $l->mac_address,
                'ip_address'  => $l->ip_address,
                'hostname'    => $l->hostname,
                'comment'     => $l->comment,
                'rate_limit'  => $l->rate_limit,
                'is_dynamic'  => (bool) $l->is_dynamic,
            ])->all(),
        ];
    }
}
