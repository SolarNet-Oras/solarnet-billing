<?php

namespace Tests\Feature;

use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class November1To4RecurringInvoiceRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026,11,5,12,0,0,'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();parent::tearDown();
    }

    public function test_preview_is_read_only_and_execution_requires_confirmation(): void
    {
        $customer=$this->customer('November One','4000000001',1);
        $this->artisan('billing:recover-november-1-to-4-2026')->assertSuccessful();
        $this->assertDatabaseMissing('invoices',['customer_id'=>$customer->id]);
        $summary=AutomationLog::latest('started_at')->firstOrFail()->summary;
        $this->assertSame('audit',$summary['mode']);
        $this->assertSame(1,$summary['missing_invoices']);
        $this->assertSame(0,$summary['future_cycles_touched']);
        $this->artisan('billing:recover-november-1-to-4-2026',['--execute'=>true,'--confirm'=>'wrong'])->assertFailed();
    }

    public function test_all_four_dates_are_recovered_once_without_touching_november_five_or_future_cycles(): void
    {
        foreach(range(1,4)as$day)$this->customer('Cycle '.$day,'400000000'.$day,$day);
        $arguments=['--execute'=>true,'--confirm'=>'RECOVER NOVEMBER 1-4 2026 CYCLES'];
        $this->artisan('billing:recover-november-1-to-4-2026',$arguments)->assertSuccessful();
        $this->artisan('billing:recover-november-1-to-4-2026',$arguments)->assertSuccessful();
        $this->assertDatabaseCount('invoices',4);
        foreach(range(1,4)as$day){
            $invoice=Invoice::whereDate('recurring_cycle_date',"2026-11-0{$day}")->sole();
            $this->assertSame("2026-10-0{$day}",$invoice->billing_period_start->toDateString());
            $this->assertSame('AUTOMATION_RECOVERY',$invoice->generation_source);
        }
        $this->assertFalse(Invoice::whereDate('recurring_cycle_date','2026-11-05')->exists());
        $this->assertFalse(Invoice::where('recurring_cycle_date','>','2026-11-04')->exists());
    }

    public function test_exact_cycle_is_preserved_but_cancelled_and_unrelated_manual_cycles_do_not_block(): void
    {
        $covered=$this->customer('Covered','4000000011',1);
        $this->invoice($covered,'EXACT-PAID','2026-10-01','2026-11-01','paid',800,0,'manual');
        $cancelled=$this->customer('Cancelled','4000000012',2);
        $this->invoice($cancelled,'EXACT-CANCELLED','2026-10-02','2026-11-02','cancelled',0,800,'manual');
        $historical=$this->customer('Historical','4000000013',3);
        $this->invoice($historical,'OLD-PAID','2026-06-03','2026-07-03','paid',800,0,'manual','2026-11-03');

        $this->artisan('billing:recover-november-1-to-4-2026',[
            '--execute'=>true,'--confirm'=>'RECOVER NOVEMBER 1-4 2026 CYCLES',
        ])->assertSuccessful();
        $this->assertSame(1,Invoice::where('customer_id',$covered->id)->count());
        $this->assertSame(2,Invoice::where('customer_id',$cancelled->id)->count());
        $this->assertSame(2,Invoice::where('customer_id',$historical->id)->count());
    }

    private function customer(string $name,string $account,int $day): Customer
    {
        return Customer::create([
            'account_number'=>$account,'full_name'=>$name,'address'=>'Test address','contact_number'=>'09170000000',
            'installation_date'=>'2026-01-01','billing_cycle_day'=>$day,'monthly_fee'=>800,'status'=>'active',
        ]);
    }

    private function invoice(Customer $customer,string $number,string $start,string $end,string $status,float $paid,float $balance,string $source,string $due=null): Invoice
    {
        return Invoice::create([
            'customer_id'=>$customer->id,'invoice_number'=>$number,'issue_date'=>'2026-10-25','due_date'=>$due??$end,
            'billing_period_start'=>$start,'billing_period_end'=>$end,'generation_source'=>$source,
            'total'=>800,'paid_amount'=>$paid,'balance'=>$balance,'status'=>$status,
        ]);
    }
}
