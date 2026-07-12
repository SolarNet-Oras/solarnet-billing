<?php

namespace App\Services\Ai\Tools;

use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AiTool;

class ListCustomersTool implements AiTool
{
    public function name(): string { return 'list_customers'; }

    public function schema(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'list_customers',
                'description' => 'List customers with optional filters. Use this for questions like "show unpaid clients", "who is suspended", or "list customers on Plan A". Returns up to 50 rows.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [
                            'type'        => 'string',
                            'enum'        => ['active', 'suspended', 'expired', 'pending'],
                            'description' => 'Filter by customer subscription status.',
                        ],
                        'search' => [
                            'type'        => 'string',
                            'description' => 'Free-text search on account_number / full_name / email / contact_number / mac_address.',
                        ],
                        'service_plan_id' => [
                            'type'        => 'string',
                            'description' => 'UUID of a service plan to filter by.',
                        ],
                        'router_id' => [
                            'type'        => 'string',
                            'description' => 'UUID of a MikroTik router to filter by.',
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Maximum rows (1-50). Default 20.',
                            'minimum'     => 1,
                            'maximum'     => 50,
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasPermission('view-customers') || $user->hasRole('super_admin');
    }

    public function execute(User $user, array $arguments): array
    {
        $q = Customer::query()->with(['servicePlan:id,name,price', 'router:id,name']);
        if (!empty($arguments['status']))          $q->where('status', $arguments['status']);
        if (!empty($arguments['service_plan_id'])) $q->where('service_plan_id', $arguments['service_plan_id']);
        if (!empty($arguments['router_id']))       $q->where('router_id', $arguments['router_id']);
        if (!empty($arguments['search']))          $q->search($arguments['search']);

        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $customers = $q->orderBy('created_at', 'desc')->limit($limit)->get();

        $rows = $customers->map(function (Customer $c) {
            return [
                'id'             => $c->id,
                'account_number' => $c->account_number,
                'full_name'      => $c->full_name,
                'status'         => $c->status,
                'monthly_fee'    => (float) $c->monthly_fee,
                'ip_address'     => $c->ip_address,
                'mac_address'    => $c->mac_address,
                'service_plan'   => $c->servicePlan?->name,
                'router'         => $c->router?->name,
            ];
        })->all();

        return [
            'count'   => count($rows),
            'limit'   => $limit,
            'filters' => array_filter([
                'status'          => $arguments['status']          ?? null,
                'search'          => $arguments['search']          ?? null,
                'service_plan_id' => $arguments['service_plan_id'] ?? null,
                'router_id'       => $arguments['router_id']       ?? null,
            ]),
            'rows'    => $rows,
        ];
    }
}
