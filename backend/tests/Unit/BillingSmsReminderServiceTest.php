<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\BillingSmsReminderService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BillingSmsReminderServiceTest extends TestCase
{
    public function test_it_only_matches_the_exact_manila_seven_day_date(): void
    {
        $invoice = new Invoice(['due_date' => '2026-09-15']);
        $service = new BillingSmsReminderService();

        $this->assertTrue($service->isExactReminderDate($invoice, Carbon::parse('2026-09-08', 'Asia/Manila')));
        $this->assertFalse($service->isExactReminderDate($invoice, Carbon::parse('2026-09-07', 'Asia/Manila')));
        $this->assertFalse($service->isExactReminderDate($invoice, Carbon::parse('2026-09-09', 'Asia/Manila')));
    }

    public function test_it_builds_a_short_authenticated_customer_portal_sms(): void
    {
        $customer = new Customer(['full_name' => 'Juan Dela Cruz']);
        $message = (new BillingSmsReminderService())->message(
            $customer,
            1500,
            Carbon::parse('2026-09-15', 'Asia/Manila'),
            'https://solarnetportal.com/customer/billing',
        );

        $this->assertSame(
            'SOLARNET: Hi Juan, your bill of PHP 1,500.00 is due Sep 15, 2026. Pay: https://solarnetportal.com/customer/billing',
            $message,
        );
    }
}
