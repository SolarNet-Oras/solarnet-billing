<?php

namespace Tests\Feature;

use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class November5RecurringInvoiceRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026,11,5,12,0,0,'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preview_is_read_only_and_confirmation_is_required(): void
    {
        $customer=$this->customer('Missing Cycle','3000000001');
        $this->artisan('billing:recover-november-5-2026')->assertSuccessful();
        $this->assertDatabaseMissing('invoices',['customer_id'=>$customer->id]);
        $summary=AutomationLog::latest('started_at')->firstOrFail()->summary;
        $this->assertSame('audit',$summary['mode']);
        $this->assertSame(1,$summary['missing_invoices']);
        $this->assertSame(0,$summary['future_cycles_touched']);

        $this->artisan('billing:recover-november-5-2026',['--execute'=>true,'--confirm'=>'wrong'])->assertFailed();
        $this->assertDatabaseCount('invoices',0);
    }

    public function test_recovery_is_idempotent_and_never_creates_a_future_cycle(): void
    {
        $customer=$this->customer('Recovered Cycle','3000000002');
        $arguments=['--execute'=>true,'--confirm'=>'RECOVER NOVEMBER 5 2026 CYCLE'];
        $this->artisan('billing:recover-november-5-2026',$arguments)->assertSuccessful();
        $this->artisan('billing:recover-november-5-2026',$arguments)->assertSuccessful();

        $this->assertSame(1,Invoice::where('customer_id',$customer->id)->count());
        $invoice=Invoice::where('customer_id',$customer->id)->sole();
        $this->assertSame('2026-10-05',$invoice->billing_period_start->toDateString());
        $this->assertSame('2026-11-05',$invoice->due_date->toDateString());
        $this->assertSame('2026-11-05',$invoice->recurring_cycle_date->toDateString());
        $this->assertSame('2026-10-29',$invoice->scheduled_generation_date->toDateString());
        $this->assertSame('AUTOMATION_RECOVERY',$invoice->generation_source);
        $this->assertDatabaseMissing('invoices',['customer_id'=>$customer->id,'recurring_cycle_date'=>'2026-12-05']);
    }

    public function test_exact_paid_cycle_is_preserved_but_unrelated_manual_payment_does_not_block_recovery(): void
    {
        $covered=$this->customer('Already Paid','3000000003');
        Invoice::create([
            'customer_id'=>$covered->id,'invoice_number'=>'PAID-EXACT','issue_date'=>'2026-09-10',
            'due_date'=>'2026-11-05','billing_period_start'=>'2026-10-05','billing_period_end'=>'2026-11-05',
            'recurring_cycle_date'=>'2026-11-05','generation_source'=>'recurring','total'=>800,
            'paid_amount'=>800,'balance'=>0,'status'=>'paid',
        ]);
        $historical=$this->customer('Historical Manual','3000000004');
        Invoice::create([
            'customer_id'=>$historical->id,'invoice_number'=>'PAID-HISTORICAL','issue_date'=>'2026-10-28',
            'due_date'=>'2026-11-05','billing_period_start'=>'2026-06-06','billing_period_end'=>'2026-07-06',
            'generation_source'=>'manual','total'=>800,'paid_amount'=>800,'balance'=>0,'status'=>'paid',
        ]);

        $this->artisan('billing:recover-november-5-2026',[
            '--execute'=>true,'--confirm'=>'RECOVER NOVEMBER 5 2026 CYCLE',
        ])->assertSuccessful();

        $this->assertSame(1,Invoice::where('customer_id',$covered->id)->count());
        $this->assertSame(2,Invoice::where('customer_id',$historical->id)->count());
        $this->assertTrue(Invoice::where('customer_id',$historical->id)
            ->whereDate('recurring_cycle_date','2026-11-05')->exists());
    }

    public function test_suspended_customer_is_included_and_cancelled_cycle_does_not_block_replacement(): void
    {
        $customer=$this->customer('Suspended Customer','3000000005','suspended');
        Invoice::create([
            'customer_id'=>$customer->id,'invoice_number'=>'CANCELLED-CYCLE','issue_date'=>'2026-10-29',
            'due_date'=>'2026-11-05','billing_period_start'=>'2026-10-05','billing_period_end'=>'2026-11-05',
            'generation_source'=>'manual','total'=>800,'paid_amount'=>0,'balance'=>800,'status'=>'cancelled',
        ]);

        $this->artisan('billing:recover-november-5-2026',[
            '--execute'=>true,'--confirm'=>'RECOVER NOVEMBER 5 2026 CYCLE',
        ])->assertSuccessful();

        $this->assertSame(2,Invoice::where('customer_id',$customer->id)->count());
        $this->assertTrue(Invoice::where('customer_id',$customer->id)
            ->whereDate('recurring_cycle_date','2026-11-05')->where('status','!=','cancelled')->exists());
    }

    private function customer(string $name,string $account,string $status='active'): Customer
    {
        return Customer::create([
            'account_number'=>$account,'full_name'=>$name,'address'=>'Test address',
            'contact_number'=>'09170000000','installation_date'=>'2026-01-01',
            'billing_cycle_day'=>5,'monthly_fee'=>800,'status'=>$status,
        ]);
    }
}
