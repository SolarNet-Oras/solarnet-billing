<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaasSubscriptionPlanConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private array $plan = [
        '--code' => 'monthly-3499',
        '--name' => 'Monthly ISP Subscription',
        '--amount' => '3499',
        '--trial-days' => '7',
        '--grace-days' => '3',
    ];

    public function test_default_mode_is_a_read_only_audit(): void
    {
        $this->artisan('saas:configure-plan', $this->plan)->assertSuccessful();
        $this->assertDatabaseCount('saas_subscription_plans', 0);
    }

    public function test_execution_requires_exact_plan_confirmation(): void
    {
        $this->artisan('saas:configure-plan', $this->plan + [
            '--execute' => true,
            '--confirm' => 'CREATE WRONG PLAN',
        ])->assertFailed();
        $this->assertDatabaseCount('saas_subscription_plans', 0);
    }

    public function test_confirmed_execution_creates_the_approved_plan(): void
    {
        $this->artisan('saas:configure-plan', $this->plan + [
            '--execute' => true,
            '--confirm' => 'CREATE SAAS PLAN MONTHLY-3499',
        ])->assertSuccessful();

        $this->assertDatabaseHas('saas_subscription_plans', [
            'code' => 'monthly-3499',
            'amount' => 3499,
            'currency' => 'PHP',
            'billing_interval' => 'monthly',
            'trial_days' => 7,
            'grace_days' => 3,
            'is_active' => true,
        ]);
    }

    public function test_existing_plan_is_never_silently_modified(): void
    {
        $arguments = $this->plan + [
            '--execute' => true,
            '--confirm' => 'CREATE SAAS PLAN MONTHLY-3499',
        ];
        $this->artisan('saas:configure-plan', $arguments)->assertSuccessful();
        $this->artisan('saas:configure-plan', $arguments)->assertFailed();

        $this->assertDatabaseCount('saas_subscription_plans', 1);
        $this->assertDatabaseHas('saas_subscription_plans', ['code' => 'monthly-3499', 'amount' => 3499]);
    }
}
