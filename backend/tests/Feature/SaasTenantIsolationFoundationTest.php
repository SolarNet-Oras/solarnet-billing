<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Customer;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\ServicePlan;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SaasTenantIsolationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_core_billing_roots_use_the_tenant_ownership_boundary(): void
    {
        foreach ([ServicePlan::class, Customer::class, Invoice::class, Payment::class, Remittance::class, FinancialEntry::class] as $model) {
            $this->assertContains(BelongsToTenant::class, class_uses_recursive($model));
        }
    }

    public function test_new_legacy_billing_record_inherits_primary_solarnet_ownership(): void
    {
        $solarNet = $this->tenant('solarnet', primary: true);
        app(CurrentTenant::class)->clear();

        $plan = $this->plan('SolarNet Plan');

        $this->assertSame($solarNet->id, $plan->tenant_id);
        $this->assertArrayNotHasKey('tenant_id', $plan->toArray());
    }

    public function test_enforced_queries_return_only_the_current_tenant_and_fail_closed_without_context(): void
    {
        $solarNet = $this->tenant('solarnet', primary: true);
        $branch = $this->tenant('branch-a');
        $context = app(CurrentTenant::class);
        $context->set($solarNet);
        $solarNetPlan = $this->plan('SolarNet Plan');
        $context->set($branch);
        $branchPlan = $this->plan('Branch Plan');

        config()->set('saas.enabled', true);
        config()->set('saas.domains_enabled', true);
        config()->set('saas.enforcement_mode', 'enforced');

        $context->set($solarNet);
        $this->assertSame([$solarNetPlan->id], ServicePlan::query()->pluck('id')->all());
        $this->assertSame([$branchPlan->id], ServicePlan::query()->forTenant($branch)->pluck('id')->all());

        $context->clear();
        $this->assertSame(0, ServicePlan::query()->count());
    }

    public function test_enforced_context_blocks_cross_tenant_mutation(): void
    {
        $solarNet = $this->tenant('solarnet', primary: true);
        $branch = $this->tenant('branch-a');
        $context = app(CurrentTenant::class);
        $context->set($branch);
        $branchPlan = $this->plan('Branch Plan');

        config()->set('saas.enabled', true);
        config()->set('saas.domains_enabled', true);
        config()->set('saas.enforcement_mode', 'enforced');
        $context->set($solarNet);

        $this->expectException(LogicException::class);
        $branchPlan->update(['name' => 'Forbidden update']);
    }

    public function test_enforced_context_assigns_ownership_before_new_record_safety_check(): void
    {
        $branch = $this->tenant('branch-a');
        config()->set('saas.enabled', true);
        config()->set('saas.domains_enabled', true);
        config()->set('saas.enforcement_mode', 'enforced');
        app(CurrentTenant::class)->set($branch);

        $entry = FinancialEntry::create([
            'type' => 'expense',
            'description' => 'Tenant-safe transaction test',
            'category' => 'Test',
            'amount' => 10,
            'entry_date' => '2026-11-09',
            'payment_method' => 'cash',
            'effect_type' => 'expense',
            'source_wallet' => 'cash',
            'idempotency_key' => fake()->uuid(),
        ]);

        $this->assertSame($branch->id, $entry->tenant_id);
        $this->assertDatabaseHas('financial_entries', ['id' => $entry->id, 'tenant_id' => $branch->id]);
    }

    public function test_disabled_enforcement_preserves_existing_billing_visibility(): void
    {
        $solarNet = $this->tenant('solarnet', primary: true);
        $branch = $this->tenant('branch-a');
        $context = app(CurrentTenant::class);
        $context->set($solarNet);
        $this->plan('SolarNet Plan');
        $context->set($branch);
        $this->plan('Branch Plan');

        config()->set('saas.enabled', false);
        config()->set('saas.domains_enabled', false);
        config()->set('saas.enforcement_mode', 'disabled');

        $this->assertSame(2, ServicePlan::query()->count());
    }

    private function tenant(string $slug, bool $primary = false): Tenant
    {
        return Tenant::create([
            'slug' => $slug,
            'name' => $slug === 'solarnet' ? 'SolarNet' : 'Branch A',
            'status' => 'active',
            'is_primary' => $primary,
            'subscription_exempt' => $primary,
        ]);
    }

    private function plan(string $name): ServicePlan
    {
        return ServicePlan::create([
            'name' => $name,
            'price' => 800,
            'download_speed' => 50,
            'upload_speed' => 50,
            'priority' => 8,
            'is_active' => true,
        ]);
    }
}
