<?php

namespace App\Services\Ai\Tools;

use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AiTool;

class PrepareCustomerStatusChangeTool implements AiTool
{
    public function name(): string { return 'prepare_customer_status_change'; }
    public function schema(): array { return ['type' => 'function', 'function' => ['name' => $this->name(), 'description' => 'Prepare a customer status change. This does not change anything. The user must reply Confirm before it can run.', 'parameters' => ['type' => 'object', 'properties' => ['customer' => ['type' => 'string', 'description' => 'Exact account number or full customer name.'], 'status' => ['type' => 'string', 'enum' => ['active', 'suspended', 'expired', 'pending']]], 'required' => ['customer', 'status']]]]; }
    public function authorize(User $user): bool { return $user->hasRole('super_admin') || $user->hasPermission('edit-customers'); }
    public function execute(User $user, array $arguments): array
    {
        $query = trim((string) ($arguments['customer'] ?? ''));
        $status = (string) ($arguments['status'] ?? '');
        $customers = Customer::where('account_number', $query)->orWhereRaw('LOWER(full_name) = ?', [mb_strtolower($query)])->limit(2)->get();
        if ($customers->count() !== 1) return ['error' => $customers->isEmpty() ? 'No exact customer match found.' : 'More than one customer matches. Use the account number.'];
        $customer = $customers->first();
        $action = AiPendingAction::create(['user_id' => $user->id, 'action' => 'customer_status_change', 'payload' => ['customer_id' => $customer->id, 'customer' => $customer->full_name, 'account_number' => $customer->account_number, 'from_status' => $customer->status, 'to_status' => $status], 'expires_at' => now()->addMinutes(15)]);
        return ['confirmation_required' => true, 'action_id' => $action->id, 'expires_at' => $action->expires_at->toIso8601String(), 'summary' => "Change {$customer->full_name} ({$customer->account_number}) from {$customer->status} to {$status}. Reply Confirm to apply."];
    }
}
