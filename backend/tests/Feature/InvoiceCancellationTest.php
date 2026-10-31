<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\InvoiceController;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class InvoiceCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancellation_changes_only_the_exact_selected_invoice(): void
    {
        config()->set('billing.invoice_cancellation_authorized_email', 'solarnet.connection@gmail.com');

        $role = Role::create([
            'name' => 'super_admin',
            'display_name' => 'Super Administrator',
            'is_active' => true,
        ]);
        $actor = User::factory()->create([
            'name' => 'Luke Lombendencio',
            'email' => 'solarnet.connection@gmail.com',
        ]);
        $actor->roles()->attach($role);

        $customer = Customer::create([
            'account_number' => '1234567890',
            'full_name' => 'One Customer Two Invoices',
            'address' => 'Test address',
            'contact_number' => '09170000000',
            'installation_date' => '2026-01-01',
            'billing_cycle_day' => 19,
            'monthly_fee' => 800,
            'status' => 'active',
        ]);

        $selected = $this->invoice($customer, 'SLR-202610-0001', '2026-10-19');
        $other = $this->invoice($customer, 'SLR-202611-0001', '2026-11-19');

        $request = Request::create('/api/v1/invoices/'.$selected->id, 'DELETE', [
            'invoice_number' => $selected->invoice_number,
            'reason' => 'Accidental duplicate statement for this cycle.',
        ]);
        $request->setUserResolver(fn () => $actor);

        app(InvoiceController::class)->destroy($request, $selected->id);

        $this->assertSame('cancelled', $selected->fresh()->status);
        $this->assertSame('sent', $other->fresh()->status);
        $this->assertSame(1, Invoice::where('status', 'cancelled')->count());
    }

    public function test_cancelled_invoice_cannot_be_used_as_a_payment_entry_point(): void
    {
        $customer = Customer::create([
            'account_number' => '1234567891',
            'full_name' => 'Cancelled Payment Guard',
            'address' => 'Test address',
            'contact_number' => '09170000001',
            'installation_date' => '2026-01-01',
            'billing_cycle_day' => 19,
            'monthly_fee' => 800,
            'status' => 'active',
        ]);
        $cancelled = $this->invoice($customer, 'SLR-202610-0002', '2026-10-19');
        $cancelled->update(['status' => 'cancelled']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A payment cannot be recorded from a cancelled invoice.');

        app(InvoiceService::class)->recordPayment($cancelled, [
            'amount' => 800,
            'payment_method' => 'cash',
            'payment_date' => '2026-10-31',
        ]);
    }

    private function invoice(Customer $customer, string $number, string $dueDate): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => $number,
            'issue_date' => '2026-10-12',
            'due_date' => $dueDate,
            'billing_period_start' => '2026-09-19',
            'billing_period_end' => $dueDate,
            'total' => 800,
            'paid_amount' => 0,
            'balance' => 800,
            'status' => 'sent',
        ]);
    }
}
