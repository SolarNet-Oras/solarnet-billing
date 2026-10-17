<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\PaymongoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymongoHostedCheckoutMethodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hosted_checkout_offers_gcash_maya_and_direct_online_banking(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_checkout');
        config()->set('services.paymongo.base_url', 'https://api.paymongo.com/v1');

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_test_multichannel',
                    'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_multichannel'],
                ],
            ], 200),
        ]);

        $customer = Customer::create([
            'account_number' => '2000000099',
            'full_name' => 'Checkout Customer',
            'email' => 'checkout@example.com',
            'address' => 'Test address',
            'contact_number' => '09170000000',
            'installation_date' => '2026-01-01',
            'billing_cycle_day' => 15,
            'monthly_fee' => 800,
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'SLR-TEST-0099',
            'issue_date' => '2026-10-08',
            'due_date' => '2026-10-15',
            'billing_period_start' => '2026-09-15',
            'billing_period_end' => '2026-10-15',
            'total' => 800,
            'balance' => 800,
            'status' => 'sent',
        ]);

        $result = app(PaymongoService::class)->createGcashCheckout($invoice);

        $this->assertSame('https://checkout.paymongo.com/cs_test_multichannel', $result['checkout_url']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.paymongo.com/v1/checkout_sessions'
                && $request['data']['attributes']['payment_method_types'] === ['gcash', 'paymaya', 'dob'];
        });
    }
}
