<?php

namespace Tests\Unit;

use App\Models\Invoice;
use PHPUnit\Framework\TestCase;

class InvoiceNotificationPolicyTest extends TestCase
{
    public function test_only_recurring_invoices_allow_automatic_billing_notifications(): void
    {
        $manual = new Invoice(['generation_source' => 'manual']);
        $collector = new Invoice(['generation_source' => 'collector_early']);
        $migration = new Invoice(['generation_source' => 'migration']);
        $recurring = new Invoice(['generation_source' => 'recurring']);

        $this->assertFalse($manual->allowsAutomaticBillingNotifications());
        $this->assertFalse($collector->allowsAutomaticBillingNotifications());
        $this->assertFalse($migration->allowsAutomaticBillingNotifications());
        $this->assertTrue($recurring->allowsAutomaticBillingNotifications());
    }
}
