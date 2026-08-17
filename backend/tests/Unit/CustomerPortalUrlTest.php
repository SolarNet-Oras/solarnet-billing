<?php

namespace Tests\Unit;

use App\Support\CustomerPortalUrl;
use Tests\TestCase;

class CustomerPortalUrlTest extends TestCase
{
    public function test_customer_urls_use_the_separate_portal_host(): void
    {
        config()->set('app.url', 'https://billing.solarnetportal.com');
        config()->set('app.customer_portal_url', 'https://solarnetportal.com');

        $this->assertTrue(CustomerPortalUrl::isValidHttpsBase());
        $this->assertSame('https://solarnetportal.com/customer/login', CustomerPortalUrl::to('/customer/login'));
        $this->assertSame('https://solarnetportal.com/customer/billing', CustomerPortalUrl::to('/customer/billing'));
        $this->assertSame(
            'https://solarnetportal.com/customer/login',
            CustomerPortalUrl::paymentReminder('https://billing.solarnetconnection.com/customer/login'),
        );
    }
}
