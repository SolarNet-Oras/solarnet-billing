<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\InvoiceController;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class InvoiceIndexCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_date_calendar_returns_only_the_selected_day_and_status_counts(): void
    {
        $customer = $this->customer('Regina Calendar', '1111111111');
        $this->invoice($customer, 'SLR-CALENDAR-PAID', '2026-11-04', 'paid', 0, 0);
        $this->invoice($customer, 'SLR-CALENDAR-PARTIAL', '2026-11-04', 'partial', 400, 100);
        $this->invoice($customer, 'SLR-CALENDAR-CANCELLED', '2026-11-04', 'cancelled', 800, 0);
        $this->invoice($customer, 'SLR-OTHER-DATE', '2026-10-04', 'sent', 800, 0);

        $response = app(InvoiceController::class)->index(Request::create('/api/v1/invoices', 'GET', [
            'due_date' => '2026-11-04',
            'per_page' => 100,
        ]));
        $payload = $response->getData(true);

        $this->assertCount(3, $payload['data']);
        $this->assertSame(1, $payload['summary']['selected_due']);
        $this->assertSame(4, $payload['summary']['all']);
        $this->assertSame(2, $payload['summary']['unpaid']);
        $this->assertSame(1, $payload['summary']['paid']);
        $this->assertSame(1, $payload['summary']['partial']);
        $this->assertSame(1, $payload['summary']['cancelled']);
        $this->assertSame(1, $payload['summary']['discounted']);
    }

    public function test_paid_invoice_immediately_leaves_selected_due_and_unpaid_counts(): void
    {
        $customer = $this->customer('Paid Calendar Customer', '1212121212');
        $invoice = $this->invoice($customer, 'SLR-CALENDAR-OPEN', '2026-11-05', 'overdue', 800, 0);

        $before = app(InvoiceController::class)->index(Request::create('/api/v1/invoices', 'GET', [
            'due_date' => '2026-11-05',
            'per_page' => 100,
        ]))->getData(true);

        $this->assertSame(1, $before['summary']['selected_due']);
        $this->assertSame(1, $before['summary']['unpaid']);

        $invoice->update([
            'paid_amount' => '800.00',
            'balance' => '0.00',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $after = app(InvoiceController::class)->index(Request::create('/api/v1/invoices', 'GET', [
            'due_date' => '2026-11-05',
            'per_page' => 100,
        ]))->getData(true);

        $this->assertSame(0, $after['summary']['selected_due']);
        $this->assertSame(0, $after['summary']['unpaid']);
        $this->assertSame(1, $after['summary']['paid']);
    }

    public function test_search_ignores_calendar_and_finds_matching_past_months(): void
    {
        $regina = $this->customer('Regina Historical', '2222222222');
        $other = $this->customer('Unrelated Customer', '3333333333');
        $this->invoice($regina, 'SLR-HISTORICAL', '2026-08-04', 'sent', 800, 0);
        $this->invoice($other, 'SLR-CURRENT', '2026-11-04', 'sent', 800, 0);

        $response = app(InvoiceController::class)->index(Request::create('/api/v1/invoices', 'GET', [
            'q' => '  gina   Historical  ',
            'due_date' => '2026-11-04',
            'per_page' => 100,
        ]));
        $payload = $response->getData(true);

        $this->assertCount(1, $payload['data']);
        $this->assertSame('SLR-HISTORICAL', $payload['data'][0]['invoice_number']);
        $this->assertSame('search', $payload['summary']['scope']);
        $this->assertNull($payload['summary']['selected_due_date']);

        foreach (['H', 'SLR-HIST', '222222'] as $term) {
            $lookup = app(InvoiceController::class)->index(Request::create('/api/v1/invoices', 'GET', [
                'q' => $term,
                'per_page' => 1,
            ]))->getData(true);

            $this->assertCount(1, $lookup['data']);
            $this->assertSame('SLR-HISTORICAL', $lookup['data'][0]['invoice_number']);
        }
    }

    public function test_customer_name_search_requires_every_separator_aware_token(): void
    {
        $target = $this->customer('Fred/Criselda Adao', '4444444444');
        $fredOnly = $this->customer('Fred Santos', '5555555555');
        $juanOnly = $this->customer('Juan Reyes', '6666666666');
        $cruzOnly = $this->customer('Criselda Cruz', '7777777777');

        $this->invoice($target, 'SLR-FRED-ADAO', '2026-11-04', 'sent', 800, 0);
        $this->invoice($fredOnly, 'SLR-FRED-SANTOS', '2026-11-04', 'sent', 800, 0);
        $this->invoice($juanOnly, 'SLR-JUAN-REYES', '2026-11-04', 'sent', 800, 0);
        $this->invoice($cruzOnly, 'SLR-CRISELDA-CRUZ', '2026-11-04', 'sent', 800, 0);

        foreach (['Fred Adao', 'Criselda Adao', 'fred adao', 'Fred/Criselda', 'Fred Ad'] as $term) {
            $payload = $this->search($term);
            $this->assertSame(['SLR-FRED-ADAO'], array_column($payload['data'], 'invoice_number'), $term);
        }

        foreach (['Fred Cruz', 'Juan Adao', 'Fred Reyes'] as $term) {
            $payload = $this->search($term);
            $this->assertSame([], array_column($payload['data'], 'invoice_number'), $term);
        }

        $invoiceLookup = $this->search('SLR-FRED-ADAO');
        $this->assertSame(['SLR-FRED-ADAO'], array_column($invoiceLookup['data'], 'invoice_number'));
    }

    private function customer(string $name, string $account): Customer
    {
        return Customer::create([
            'account_number' => $account,
            'full_name' => $name,
            'address' => 'Test address',
            'contact_number' => '09170000000',
            'installation_date' => '2026-01-01',
            'billing_cycle_day' => 4,
            'monthly_fee' => 800,
            'status' => 'active',
        ]);
    }

    private function search(string $term): array
    {
        return app(InvoiceController::class)->index(Request::create('/api/v1/invoices', 'GET', [
            'q' => $term,
            'per_page' => 100,
        ]))->getData(true);
    }

    private function invoice(Customer $customer, string $number, string $dueDate, string $status, float $balance, float $discount): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => $number,
            'issue_date' => '2026-10-28',
            'due_date' => $dueDate,
            'billing_period_start' => '2026-10-04',
            'billing_period_end' => $dueDate,
            'subtotal' => 800,
            'discount' => $discount,
            'total' => 800 - $discount,
            'paid_amount' => max(0, 800 - $discount - $balance),
            'balance' => $balance,
            'status' => $status,
        ]);
    }
}
