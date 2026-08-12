<?php

namespace App\Services\Ai\Tools;

use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Ai\AiTool;
use Illuminate\Support\Facades\DB;

class ConfirmPendingAiActionTool implements AiTool
{
    public function name(): string { return 'confirm_pending_action'; }
    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Apply the latest pending action only after the user explicitly replies Confirm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action_id' => [
                            'type' => 'string',
                            'description' => 'Optional pending action ID. Omit to confirm the latest action.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }
    public function authorize(User $user): bool { return $user->hasRole('super_admin') || $user->hasPermission('edit-customers') || $user->hasPermission('create-service-plans'); }
    public function execute(User $user, array $arguments): array
    {
        $q = AiPendingAction::where('user_id', $user->id)->where('status', 'pending')->where('expires_at', '>', now());
        $action = !empty($arguments['action_id']) ? $q->whereKey($arguments['action_id'])->first() : $q->latest()->first();
        if (!$action) return ['error' => 'No active pending action found. Submit a new request first.'];
        return DB::transaction(function () use ($action, $user) {
            if ($action->action === 'service_plan_create') {
                if (!$user->hasRole('super_admin') && !$user->hasPermission('create-service-plans')) return ['error' => 'Permission denied.'];
                $plan = ServicePlan::create($action->payload);
                $result = ['success' => true, 'message' => "Service plan {$plan->name} created.", 'service_plan_id' => $plan->id];
            } elseif ($action->action === 'customer_status_change') {
                if (!$user->hasRole('super_admin') && !$user->hasPermission('edit-customers')) return ['error' => 'Permission denied.'];
                $customer = Customer::find($action->payload['customer_id']);
                if (!$customer) return ['error' => 'Customer no longer exists.'];
                $customer->update(['status' => $action->payload['to_status']]);
                $result = ['success' => true, 'message' => "{$customer->full_name} is now {$customer->status}. Queue restriction will sync in the background.", 'customer_id' => $customer->id];
            } else return ['error' => 'Unsupported pending action.'];
            $action->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            return $result;
        });
    }
}
