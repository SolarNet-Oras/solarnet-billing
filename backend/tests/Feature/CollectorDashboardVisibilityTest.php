<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\RemittanceController;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CollectorDashboardVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_unassigned_customer_is_visible_but_future_invoice_is_hidden(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 08:00:00', 'Asia/Manila'));
        $collector=User::factory()->create();
        $customer=Customer::create([
            'account_number'=>'7852604220',
            'full_name'=>'Daday Mengote',
            'address'=>'San Roque Oras Eastern Samar',
            'contact_number'=>'09999999999',
            'installation_date'=>'2026-01-01',
            'monthly_fee'=>'800.00',
            'status'=>'active',
        ]);
        $due=$this->invoice($customer,'DUE-UNASSIGNED','2026-10-05');
        $this->invoice($customer,'FUTURE-UNASSIGNED','2026-11-20');
        $request=Request::create('/api/v1/collector/dashboard','GET');
        $request->setUserResolver(fn()=> $collector);

        $response=app(RemittanceController::class)->collectorDashboard($request);
        $payload=$response->getData(true);

        $this->assertSame([$due->id],array_column($payload['invoices']['data'],'id'));
    }

    private function invoice(Customer $customer,string $number,string $dueDate): Invoice
    {
        return Invoice::create([
            'invoice_number'=>$number,
            'customer_id'=>$customer->id,
            'issue_date'=>$dueDate,
            'due_date'=>$dueDate,
            'billing_period_start'=>$dueDate,
            'billing_period_end'=>$dueDate,
            'subtotal'=>'800.00',
            'tax'=>'0.00',
            'discount'=>'0.00',
            'total'=>'800.00',
            'paid_amount'=>'0.00',
            'balance'=>'800.00',
            'status'=>'sent',
        ]);
    }
}
