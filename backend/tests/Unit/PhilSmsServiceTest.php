<?php

namespace Tests\Unit;

use App\Services\PhilSmsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhilSmsServiceTest extends TestCase
{
    public function test_it_sends_a_unicode_philippine_sms_with_bearer_authentication(): void
    {
        config()->set('services.sms.driver', 'philsms');
        config()->set('services.sms.philsms_api_token', 'test-token');
        config()->set('services.sms.philsms_sender_id', 'SolarNet');
        config()->set('services.sms.philsms_base_url', 'https://dashboard.philsms.com/api/v3');

        Http::fake([
            'https://dashboard.philsms.com/api/v3/sms/send' => Http::response([
                'status' => 'success',
                'data' => ['uid' => 'sms-test-uid'],
            ], 201),
        ]);

        $delivery = app(PhilSmsService::class)->send('09171234567', 'SolarNet balance: ₱800.00');

        $this->assertSame('sent', $delivery);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://dashboard.philsms.com/api/v3/sms/send'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['recipient'] === '639171234567'
                && $request['sender_id'] === 'SolarNet'
                && $request['type'] === 'unicode';
        });
    }

    public function test_it_refuses_delivery_without_explicit_philsms_configuration(): void
    {
        config()->set('services.sms.driver', 'log');
        config()->set('services.sms.philsms_api_token', null);
        config()->set('services.sms.philsms_sender_id', null);

        Http::fake();

        $this->assertSame('skipped_not_configured', app(PhilSmsService::class)->send('09171234567', 'Test'));
        Http::assertNothingSent();
    }

    public function test_it_keeps_the_provider_rejection_reason_safe_for_the_test_command(): void
    {
        config()->set('services.sms.driver', 'philsms');
        config()->set('services.sms.philsms_api_token', 'test-token');
        config()->set('services.sms.philsms_sender_id', 'SolarNet');
        config()->set('services.sms.philsms_base_url', 'https://dashboard.philsms.com/api/v3');

        Http::fake([
            'https://dashboard.philsms.com/api/v3/sms/send' => Http::response([
                'status' => 'error',
                'message' => 'Sender ID is not approved.',
            ], 422),
        ]);

        $philSms = app(PhilSmsService::class);

        $this->assertSame('failed', $philSms->send('09171234567', 'Test'));
        $this->assertSame('PhilSMS returned HTTP 422: Sender ID is not approved.', $philSms->lastFailureReason());
    }
}
