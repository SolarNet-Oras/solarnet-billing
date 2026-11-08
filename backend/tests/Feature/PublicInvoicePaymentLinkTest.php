<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\InvoicePaymentLinkService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicInvoicePaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_payment_link_survives_an_invoice_number_correction(): void
    {
        $customer = Customer::create([
            'account_number' => '2000000199',
            'full_name' => 'Payment Link Customer',
            'email' => 'payment-link@example.com',
            'address' => 'Test address',
            'contact_number' => '09170000199',
            'installation_date' => '2026-01-01',
            'billing_cycle_day' => 15,
            'monthly_fee' => 800,
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-202610-0199',
            'issue_date' => '2026-10-08',
            'due_date' => '2026-10-15',
            'billing_period_start' => '2026-09-15',
            'billing_period_end' => '2026-10-15',
            'total' => 800,
            'balance' => 800,
            'status' => 'sent',
        ]);

        $token = app(InvoicePaymentLinkService::class)->token($invoice);
        $invoice->update(['invoice_number' => 'SLR-202610-0199']);

        $this->getJson('/api/v1/customer-portal/pay/'.$token)
            ->assertOk()
            ->assertJsonPath('data.invoice.invoice_number', 'SLR-202610-0199')
            ->assertJsonPath('data.customer.account_number', '2000000199');
    }

    public function test_tampered_payment_link_remains_rejected(): void
    {
        $this->getJson('/api/v1/customer-portal/pay/not-a-valid-token')
            ->assertNotFound();
    }

    public function test_public_payment_link_resolves_its_tenant_when_enforcement_is_enabled(): void
    {
        $tenant = Tenant::create(['slug'=>'branch-a','name'=>'Branch A','status'=>'active','is_primary'=>false,'subscription_exempt'=>false]);
        app(CurrentTenant::class)->set($tenant);
        $customer = Customer::create(['account_number'=>'3000000199','full_name'=>'Tenant Customer','address'=>'Test address','installation_date'=>'2026-01-01','billing_cycle_day'=>15,'monthly_fee'=>800,'status'=>'active']);
        $invoice = Invoice::create(['customer_id'=>$customer->id,'invoice_number'=>'TEN-0199','issue_date'=>'2026-10-08','due_date'=>'2026-10-15','billing_period_start'=>'2026-09-15','billing_period_end'=>'2026-10-15','total'=>800,'balance'=>800,'status'=>'sent']);
        $token = app(InvoicePaymentLinkService::class)->token($invoice);

        config()->set('saas.enabled',true);
        config()->set('saas.domains_enabled',true);
        config()->set('saas.enforcement_mode','enforced');
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/customer-portal/pay/'.$token)
            ->assertOk()
            ->assertJsonPath('data.invoice.invoice_number','TEN-0199');
        $this->assertSame($tenant->id,app(CurrentTenant::class)->id());
    }

    public function test_legacy_signed_solarnet_link_without_tenant_id_still_works(): void
    {
        $tenant = Tenant::create(['slug'=>'solarnet','name'=>'SolarNet','status'=>'active','is_primary'=>true,'subscription_exempt'=>true]);
        app(CurrentTenant::class)->set($tenant);
        $customer = Customer::create(['account_number'=>'4000000199','full_name'=>'Legacy Link Customer','address'=>'Test address','installation_date'=>'2026-01-01','billing_cycle_day'=>15,'monthly_fee'=>800,'status'=>'active']);
        $invoice = Invoice::create(['customer_id'=>$customer->id,'invoice_number'=>'SLR-LEGACY-0199','issue_date'=>'2026-10-08','due_date'=>'2026-10-15','billing_period_start'=>'2026-09-15','billing_period_end'=>'2026-10-15','total'=>800,'balance'=>800,'status'=>'sent']);
        $payload = ['invoice_id'=>$invoice->id,'customer_id'=>$customer->id,'invoice_number'=>$invoice->invoice_number,'expires_at'=>now()->addDays(30)->timestamp];
        $encoded = rtrim(strtr(base64_encode(json_encode($payload,JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');
        $token = $encoded.'.'.hash_hmac('sha256',$encoded,(string)config('app.key'));

        config()->set('saas.enabled',true);
        config()->set('saas.domains_enabled',true);
        config()->set('saas.enforcement_mode','enforced');
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/customer-portal/pay/'.$token)
            ->assertOk()
            ->assertJsonPath('data.invoice.invoice_number','SLR-LEGACY-0199');
    }
}
