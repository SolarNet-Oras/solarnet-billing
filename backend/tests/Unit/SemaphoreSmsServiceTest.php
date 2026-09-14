<?php

namespace Tests\Unit;

use App\Services\SemaphoreSmsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SemaphoreSmsServiceTest extends TestCase
{
    private function configure(): void
    {
        config()->set('services.sms.driver', 'semaphore');
        config()->set('services.sms.semaphore_api_key', 'test-key');
        config()->set('services.sms.semaphore_sender_name', 'SolarNet');
        config()->set('services.sms.semaphore_base_url', 'https://api.semaphore.co/api/v4');
    }

    public function test_it_sends_a_philippine_sms_using_semaphore_form_fields(): void
    {
        $this->configure();
        Http::fake(['https://api.semaphore.co/api/v4/messages' => Http::response([[
            'message_id' => 12345,
            'recipient' => '639171234567',
            'sender_name' => 'SolarNet',
            'status' => 'Pending',
        ]], 200)]);

        $service = app(SemaphoreSmsService::class);
        $this->assertSame('sent', $service->send('09171234567', 'SolarNet balance: PHP 800.00'));
        $this->assertSame('12345', $service->lastProviderMessageId());
        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://api.semaphore.co/api/v4/messages'
            && $request['apikey'] === 'test-key'
            && $request['number'] === '639171234567'
            && $request['sendername'] === 'SolarNet'
            && $request['message'] === 'SolarNet balance: PHP 800.00'
        );
    }

    public function test_it_refuses_delivery_without_explicit_configuration(): void
    {
        config()->set('services.sms.driver', 'log');
        config()->set('services.sms.semaphore_api_key', null);
        Http::fake();
        $this->assertSame('skipped_not_configured', app(SemaphoreSmsService::class)->send('09171234567', 'Hello'));
        Http::assertNothingSent();
    }

    public function test_it_reads_semaphore_account_credit_without_sending_sms(): void
    {
        $this->configure();
        Http::fake(['https://api.semaphore.co/api/v4/account*' => Http::response([
            'account_id' => 99,
            'account_name' => 'SolarNet',
            'status' => 'Active',
            'credit_balance' => 42,
        ], 200)]);

        $result = app(SemaphoreSmsService::class)->balance();
        $this->assertSame('available', $result['status']);
        $this->assertSame(42, $result['data']['credit_balance']);
        Http::assertSent(fn (Request $request): bool =>
            $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.semaphore.co/api/v4/account')
        );
    }

    public function test_it_preserves_a_safe_provider_rejection_reason(): void
    {
        $this->configure();
        Http::fake(['https://api.semaphore.co/api/v4/messages' => Http::response([
            'message' => 'Sender Name is not approved.',
        ], 422)]);

        $service = app(SemaphoreSmsService::class);
        $this->assertSame('failed', $service->send('09171234567', 'Hello'));
        $this->assertSame('Semaphore returned HTTP 422: Sender Name is not approved.', $service->lastFailureReason());
    }
}
