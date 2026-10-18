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

    public function test_hosted_checkout_offers_gcash_maya_and_bpi_ubp_direct_online_banking(): void
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
                && $request['data']['attributes']['payment_method_types'] === ['card', 'gcash', 'paymaya', 'dob'];
        });
    }

    public function test_unionbank_direct_flow_creates_dob_intent_and_records_bank_selection(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_checkout');
        config()->set('services.paymongo.public_key', 'pk_test_checkout');
        config()->set('services.paymongo.base_url', 'https://api.paymongo.com/v1');
        Http::fake([
            'https://api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => ['id' => 'pi_test_ubp', 'attributes' => ['client_key' => 'pi_test_ubp_client_key']],
            ]),
        ]);

        $customer = Customer::create([
            'account_number' => '2000000100', 'full_name' => 'UBP Customer',
            'email' => 'ubp@example.com', 'address' => 'Test address',
            'contact_number' => '09170000001', 'installation_date' => '2026-01-01',
            'billing_cycle_day' => 15, 'monthly_fee' => 800, 'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'invoice_number' => 'SLR-TEST-0100',
            'issue_date' => '2026-10-08', 'due_date' => '2026-10-15',
            'billing_period_start' => '2026-09-15', 'billing_period_end' => '2026-10-15',
            'total' => 800, 'balance' => 800, 'status' => 'sent',
        ]);

        $result = app(PaymongoService::class)->createDobPayment($invoice, 'ubp');

        $this->assertSame('ubp', $result['bank_code']);
        $this->assertDatabaseHas('paymongo_checkouts', [
            'invoice_id' => $invoice->id,
            'checkout_type' => 'dob_ubp',
            'payment_intent_id' => 'pi_test_ubp',
        ]);
        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://api.paymongo.com/v1/payment_intents'
            && $request['data']['attributes']['payment_method_allowed'] === ['dob']
        );
    }

    public function test_qrph_is_attached_and_returned_by_the_backend(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_qrph');
        config()->set('services.paymongo.public_key', 'pk_test_qrph');
        config()->set('services.paymongo.base_url', 'https://api.paymongo.com/v1');

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === 'https://api.paymongo.com/v1/payment_intents') {
                return Http::response(['data' => [
                    'id' => 'pi_test_qrph',
                    'attributes' => ['client_key' => 'pi_test_qrph_client_key'],
                ]]);
            }
            if ($request->method() === 'GET' && $request->url() === 'https://api.paymongo.com/v1/payment_intents/pi_test_qrph') {
                return Http::response(['data' => ['id' => 'pi_test_qrph', 'attributes' => [
                    'amount' => 80000, 'currency' => 'PHP', 'status' => 'awaiting_payment_method',
                ]]]);
            }
            if ($request->method() === 'POST' && $request->url() === 'https://api.paymongo.com/v1/payment_methods') {
                return Http::response(['data' => ['id' => 'pm_test_qrph']]);
            }
            if ($request->method() === 'POST' && $request->url() === 'https://api.paymongo.com/v1/payment_intents/pi_test_qrph/attach') {
                return Http::response(['data' => ['attributes' => ['next_action' => ['code' => [
                    'image_url' => 'data:image/png;base64,dGVzdA==',
                ]]]]]);
            }

            return Http::response(['errors' => [['detail' => 'Unexpected test request']]], 500);
        });

        $customer = Customer::create([
            'account_number' => '2000000101', 'full_name' => 'QRPh Customer',
            'email' => 'qrph@example.com', 'address' => 'Test address',
            'contact_number' => '09170000002', 'installation_date' => '2026-01-01',
            'billing_cycle_day' => 15, 'monthly_fee' => 800, 'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'invoice_number' => 'SLR-TEST-0101',
            'issue_date' => '2026-10-08', 'due_date' => '2026-10-15',
            'billing_period_start' => '2026-09-15', 'billing_period_end' => '2026-10-15',
            'total' => 800, 'balance' => 800, 'status' => 'sent',
        ]);

        $result = app(PaymongoService::class)->createQrPhPayment($invoice);

        $this->assertSame('pm_test_qrph', $result['payment_method_id']);
        $this->assertSame('data:image/png;base64,dGVzdA==', $result['qr_image_url']);
        $this->assertDatabaseHas('paymongo_checkouts', [
            'invoice_id' => $invoice->id,
            'checkout_type' => 'qr_ph',
            'payment_method_id' => 'pm_test_qrph',
        ]);
        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://api.paymongo.com/v1/payment_methods'
            && $request['data']['attributes']['type'] === 'qrph'
        );
    }
}
