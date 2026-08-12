<?php

namespace App\Services\Ai\Tools;

use App\Models\AiPendingAction;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Ai\AiTool;

class PrepareServicePlanTool implements AiTool
{
    public function name(): string { return 'prepare_service_plan'; }
    public function schema(): array { return ['type' => 'function', 'function' => ['name' => $this->name(), 'description' => 'Prepare a new service plan. This does not create it until the user replies Confirm.', 'parameters' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'price' => ['type' => 'number'], 'download_speed' => ['type' => 'integer'], 'upload_speed' => ['type' => 'integer'], 'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8], 'description' => ['type' => 'string']], 'required' => ['name', 'price', 'download_speed', 'upload_speed', 'priority']]]]; }
    public function authorize(User $user): bool { return $user->hasRole('super_admin') || $user->hasPermission('create-service-plans'); }
    public function execute(User $user, array $arguments): array
    {
        $payload = ['name' => trim((string) $arguments['name']), 'price' => (float) $arguments['price'], 'download_speed' => (int) $arguments['download_speed'], 'upload_speed' => (int) $arguments['upload_speed'], 'priority' => (int) $arguments['priority'], 'description' => isset($arguments['description']) ? trim((string) $arguments['description']) : null, 'is_active' => true];
        if ($payload['name'] === '' || $payload['price'] < 0 || $payload['download_speed'] < 1 || $payload['upload_speed'] < 1 || $payload['priority'] < 1 || $payload['priority'] > 8) return ['error' => 'Plan name, price, download speed, upload speed, and priority 1–8 are required.'];
        if (ServicePlan::whereRaw('LOWER(name) = ?', [mb_strtolower($payload['name'])])->exists()) return ['error' => 'A service plan with this name already exists.'];
        $action = AiPendingAction::create(['user_id' => $user->id, 'action' => 'service_plan_create', 'payload' => $payload, 'expires_at' => now()->addMinutes(15)]);
        return ['confirmation_required' => true, 'action_id' => $action->id, 'expires_at' => $action->expires_at->toIso8601String(), 'summary' => "Create {$payload['name']}: {$payload['download_speed']} Mbps / {$payload['upload_speed']} Mbps at ₱{$payload['price']} monthly. Reply Confirm to apply."];
    }
}
