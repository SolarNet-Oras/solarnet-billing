<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Services\BillingSuspensionService;
use App\Services\FinalGracePeriodWarningService;
use Carbon\Carbon;
use Tests\TestCase;

class FinalGracePeriodWarningServiceTest extends TestCase
{
    public function test_final_warning_uses_the_existing_suspension_date_math(): void
    {
        $dates = BillingSuspensionService::gracePeriodDates(
            Carbon::parse('2026-09-15', 'Asia/Manila'),
            5,
        );

        $this->assertSame('2026-09-16', $dates['grace_period_start']->toDateString());
        $this->assertSame('2026-09-20', $dates['grace_period_end']->toDateString());
        $this->assertSame('2026-09-21', $dates['suspension_at']->toDateString());
    }

    public function test_sms_uses_authoritative_balance_and_authenticated_portal_url(): void
    {
        $customer = new Customer(['full_name' => 'Juan Dela Cruz']);
        $service = new FinalGracePeriodWarningService();
        $message = $service->smsMessage($customer, [
            'outstanding_balance' => 3000,
            'grace_days' => 5,
            'portal_url' => 'https://billing.solarnetconnection.com/customer/billing',
        ]);

        $this->assertSame(
            'SOLARNET: FINAL WARNING. Your account has an outstanding balance of PHP 3,000.00. Your 5-day grace period ends today. Please settle now to avoid service suspension. Pay: https://billing.solarnetconnection.com/customer/billing',
            $message,
        );
    }
}
