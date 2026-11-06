<?php

namespace App\Console\Commands;

use App\Models\SaasSubscriptionPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ConfigureSaasSubscriptionPlan extends Command
{
    protected $signature = 'saas:configure-plan
                            {--code= : Unique lowercase plan code}
                            {--name= : Customer-facing plan name}
                            {--amount= : Monthly price in PHP}
                            {--trial-days= : Free-trial duration}
                            {--grace-days= : Grace period after subscription expiry}
                            {--execute : Create the plan after a successful audit}
                            {--confirm= : Exact plan-specific confirmation phrase}';

    protected $description = 'Audit or safely create one SaaS subscription plan without modifying existing plans';

    public function handle(): int
    {
        $code = Str::lower(trim((string) $this->option('code')));
        $name = trim((string) $this->option('name'));
        $amount = filter_var($this->option('amount'), FILTER_VALIDATE_FLOAT);
        $trialDays = filter_var($this->option('trial-days'), FILTER_VALIDATE_INT);
        $graceDays = filter_var($this->option('grace-days'), FILTER_VALIDATE_INT);
        $existing = $code === '' ? null : SaasSubscriptionPlan::withTrashed()->where('code', $code)->first();
        $errors = [];

        if (! preg_match('/^[a-z0-9-]{3,100}$/', $code)) {
            $errors[] = 'invalid_plan_code';
        }
        if ($name === '' || mb_strlen($name) > 255) {
            $errors[] = 'invalid_plan_name';
        }
        if ($amount === false || $amount < 1 || $amount > 999999.99) {
            $errors[] = 'invalid_monthly_amount';
        }
        if ($trialDays === false || $trialDays < 0 || $trialDays > 365) {
            $errors[] = 'invalid_trial_days';
        }
        if ($graceDays === false || $graceDays < 0 || $graceDays > 90) {
            $errors[] = 'invalid_grace_days';
        }
        if ($existing) {
            $errors[] = $existing->trashed() ? 'plan_code_is_archived' : 'plan_code_already_exists';
        }

        $preview = [
            'mode' => $this->option('execute') ? 'execute_requested' : 'audit',
            'read_only' => ! $this->option('execute'),
            'plan' => [
                'code' => $code,
                'name' => $name,
                'amount' => $amount === false ? null : round($amount, 2),
                'currency' => 'PHP',
                'billing_interval' => 'monthly',
                'trial_days' => $trialDays === false ? null : $trialDays,
                'grace_days' => $graceDays === false ? null : $graceDays,
                'is_active' => true,
            ],
            'ready' => $errors === [],
            'errors' => $errors,
            'action' => $errors === [] ? 'ready_for_explicit_confirmation' : 'blocked_no_changes',
        ];
        $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! $this->option('execute')) {
            return self::SUCCESS;
        }
        if ($errors !== []) {
            return self::FAILURE;
        }

        $confirmation = 'CREATE SAAS PLAN '.Str::upper($code);
        if (! hash_equals($confirmation, (string) $this->option('confirm'))) {
            $this->error('Execution stopped. Use --confirm="'.$confirmation.'".');

            return self::FAILURE;
        }

        $plan = SaasSubscriptionPlan::create([
            'code' => $code,
            'name' => $name,
            'description' => sprintf('%d-day free trial, then PHP %s monthly with a %d-day grace period.', $trialDays, number_format($amount, 2, '.', ','), $graceDays),
            'amount' => round($amount, 2),
            'currency' => 'PHP',
            'billing_interval' => 'monthly',
            'trial_days' => $trialDays,
            'grace_days' => $graceDays,
            'features' => ['tenant_billing'],
            'is_active' => true,
        ]);

        $this->line(json_encode([
            'mode' => 'execute',
            'action' => 'subscription_plan_created_and_verified',
            'plan' => $plan->only(['id', 'code', 'name', 'amount', 'currency', 'billing_interval', 'trial_days', 'grace_days', 'is_active']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
