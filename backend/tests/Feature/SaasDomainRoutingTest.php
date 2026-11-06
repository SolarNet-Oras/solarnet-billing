<?php

namespace Tests\Feature;

use Tests\TestCase;

class SaasDomainRoutingTest extends TestCase
{
    public function test_tenant_application_domain_is_recognized_but_inert_by_default(): void
    {
        $this->withHeader('Host', 'app.solarnetportal.com')
            ->getJson('/api/v1/saas/domain-context')
            ->assertOk()
            ->assertJson([
                'surface' => 'app',
                'host' => 'app.solarnetportal.com',
                'domains_enabled' => false,
                'saas_enabled' => false,
                'registration_enabled' => false,
                'enforcement_mode' => 'disabled',
            ]);
    }

    public function test_platform_admin_domain_is_distinct_from_tenant_application(): void
    {
        $this->withHeader('Host', 'admin.solarnetportal.com')
            ->getJson('/api/v1/saas/domain-context')
            ->assertOk()
            ->assertJsonPath('surface', 'admin');
    }

    public function test_existing_billing_domain_remains_the_primary_billing_surface(): void
    {
        $this->withHeader('Host', 'billing.solarnetportal.com')
            ->getJson('/api/v1/saas/domain-context')
            ->assertOk()
            ->assertJsonPath('surface', 'billing');
    }

    public function test_unknown_host_never_becomes_a_tenant_surface(): void
    {
        $this->withHeader('Host', 'unknown.example.test')
            ->getJson('/api/v1/saas/domain-context')
            ->assertOk()
            ->assertJsonPath('surface', 'public');
    }

    public function test_staged_tenant_domain_cannot_call_legacy_authentication_api(): void
    {
        $this->withHeader('Host', 'app.solarnetportal.com')
            ->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'secret'])
            ->assertStatus(503)
            ->assertJsonPath('surface', 'app');
    }

    public function test_staged_platform_domain_cannot_call_legacy_billing_api(): void
    {
        $this->withHeader('Host', 'admin.solarnetportal.com')
            ->getJson('/api/v1/invoices')
            ->assertStatus(503)
            ->assertJsonPath('surface', 'admin');
    }
}
