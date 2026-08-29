<?php

namespace Tests\Unit;

use App\Console\Commands\Automation\SendInvoiceReminders;
use App\Models\Invoice;
use App\Services\CustomerWebPushNotificationService;
use Carbon\Carbon;
use Tests\TestCase;

class CustomerWebPushNotificationServiceTest extends TestCase
{
    public function test_push_is_disabled_without_explicit_server_configuration(): void
    {
        config()->set('services.web_push.enabled', false);
        config()->set('services.web_push.vapid_subject', null);
        config()->set('services.web_push.vapid_public_key', null);
        config()->set('services.web_push.vapid_private_key', null);

        $this->assertFalse(app(CustomerWebPushNotificationService::class)->isConfigured());
    }

    public function test_daily_web_push_schedule_covers_billing_and_grace_events(): void
    {
        $command = app(SendInvoiceReminders::class);
        $method = new \ReflectionMethod($command, 'pushTypeFor');
        $method->setAccessible(true);

        $this->assertSame(CustomerWebPushNotificationService::BILLING_REMINDER_7_DAYS, $method->invoke($command, 7, 15));
        $this->assertSame(CustomerWebPushNotificationService::BILLING_DAILY_REMINDER, $method->invoke($command, 6, 15));
        $this->assertSame(CustomerWebPushNotificationService::BILLING_DUE_TODAY, $method->invoke($command, 0, 15));
        $this->assertSame(CustomerWebPushNotificationService::BILLING_OVERDUE, $method->invoke($command, -1, 15));
        $this->assertSame(CustomerWebPushNotificationService::BILLING_DAILY_REMINDER, $method->invoke($command, -2, 15));
        $this->assertSame(CustomerWebPushNotificationService::GRACE_PERIOD_WARNING, $method->invoke($command, -8, 15));
        $this->assertSame(CustomerWebPushNotificationService::BILLING_DAILY_REMINDER, $method->invoke($command, -14, 15));
        $this->assertNull($method->invoke($command, -15, 15));
    }

    public function test_reminder_due_date_uses_manila_calendar_days_for_sms_eligibility(): void
    {
        $command = app(SendInvoiceReminders::class);
        $method = new \ReflectionMethod($command, 'daysUntilDue');
        $method->setAccessible(true);
        $invoice = new Invoice(['due_date' => '2026-09-05']);
        $today = Carbon::parse('2026-08-29', 'Asia/Manila')->startOfDay();

        $this->assertSame(7, $method->invoke($command, $invoice, $today));
    }
}
