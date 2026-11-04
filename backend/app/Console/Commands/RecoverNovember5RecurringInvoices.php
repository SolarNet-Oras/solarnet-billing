<?php

namespace App\Console\Commands;

use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Automation\AutomationRunner;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecoverNovember5RecurringInvoices extends Command
{
    private const CONFIRMATION = 'RECOVER NOVEMBER 5 2026 CYCLE';

    protected $signature = 'billing:recover-november-5-2026
                            {--execute : Create only missing November 5 recurring statements}
                            {--confirm= : Exact execution confirmation phrase}
                            {--user-id= : Super Administrator user UUID for the audit log}';

    protected $description = 'Audit or recover only the recurring cycle due November 5, 2026';

    public function handle(InvoiceService $invoiceService): int
    {
        $execute=(bool)$this->option('execute');
        if($execute&&!hash_equals(self::CONFIRMATION,(string)$this->option('confirm'))){
            $this->error('Execution stopped. Use --confirm="'.self::CONFIRMATION.'".');
            return self::FAILURE;
        }

        $log=AutomationRunner::run(
            AutomationLog::JOB_NOVEMBER_5_2026_INVOICE_RECOVERY,
            $execute?'manual_recovery':'manual_audit',
            $this->option('user-id')?:null,
            fn()=>$this->recover($invoiceService,$execute),
        );

        $this->line(json_encode($log->summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        return $log->status===AutomationLog::STATUS_SUCCESS?self::SUCCESS:self::FAILURE;
    }

    private function recover(InvoiceService $invoiceService,bool $execute): array
    {
        $timezone='Asia/Manila';
        $dueDate=Carbon::create(2026,11,5,0,0,0,$timezone);
        $scheduledDate=$dueDate->copy()->subDays(7);
        $periodStart=$dueDate->copy()->subMonthNoOverflow()->startOfDay();

        $customers=Customer::query()
            ->whereIn('status',['active','suspended'])
            ->whereNotNull('installation_date')
            ->with('servicePlan')
            ->orderBy('full_name')
            ->get()
            ->filter(fn(Customer $customer)=>$customer->billingCycleDay()!==null
                &&min($customer->billingCycleDay(),$dueDate->daysInMonth)===$dueDate->day);

        $details=[];$existingCount=0;$missingCount=0;$createdCount=0;$errors=[];
        foreach($customers as $customer){
            $amount=round((float)($customer->servicePlan?->price??$customer->monthly_fee),2);
            $existing=$this->existingCycleInvoice($customer->id,$dueDate,$periodStart);
            $row=[
                'customer'=>$customer->full_name,'customer_id'=>$customer->id,
                'account_number'=>$customer->account_number,'customer_status'=>$customer->status,
                'billing_cycle'=>$periodStart->toDateString().' to '.$dueDate->toDateString(),
                'scheduled_generation_date'=>$scheduledDate->toDateString(),'due_date'=>$dueDate->toDateString(),
                'amount'=>$amount,'existing_invoice_number'=>$existing?->invoice_number,
                'existing_invoice_status'=>$existing?->status,'action'=>null,'created_invoice_number'=>null,'error'=>null,
            ];

            if($existing){
                $existingCount++;$row['action']='skipped_existing_cycle';$details[]=$row;continue;
            }
            if($periodStart->lt($customer->installation_date->copy()->startOfDay())){
                $row['action']='skipped_before_first_full_service_cycle';$details[]=$row;continue;
            }
            if($customer->hasCompanyOwnedPlan()){
                $row['action']='skipped_company_owned_plan';$details[]=$row;continue;
            }
            if($amount<=0){
                $row['action']='skipped_invalid_amount';$details[]=$row;continue;
            }

            $missingCount++;
            if(!$execute){$row['action']='would_create';$details[]=$row;continue;}

            try{
                $result=DB::transaction(function()use($invoiceService,$customer,$periodStart,$dueDate,$scheduledDate){
                    $lockedCustomer=Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                    $lockedCustomer->load('servicePlan');
                    $existing=$this->existingCycleInvoice($customer->id,$dueDate,$periodStart,true);
                    if($existing)return ['created'=>false,'invoice'=>$existing];

                    $invoice=$invoiceService->generateInvoice(
                        $lockedCustomer,$periodStart,$dueDate,[],now('Asia/Manila'),$dueDate,$dueDate,
                        'AUTOMATION_RECOVERY',$scheduledDate,
                    );
                    if($invoice->generation_source!=='AUTOMATION_RECOVERY')return ['created'=>false,'invoice'=>$invoice];
                    $invoiceService->markAsSent($invoice);
                    return ['created'=>true,'invoice'=>$invoice->fresh()];
                },3);
                $row['created_invoice_number']=$result['invoice']->invoice_number;
                $row['existing_invoice_status']=$result['invoice']->status;
                $row['action']=$result['created']?'created':'skipped_concurrent_existing_cycle';
                if($result['created'])$createdCount++;
                else $existingCount++;
            }catch(\Throwable $exception){
                $row['action']='error';$row['error']=$exception->getMessage();
                $errors[]=['customer_id'=>$customer->id,'account_number'=>$customer->account_number,'error'=>$exception->getMessage()];
            }
            $details[]=$row;
        }

        $verified=Invoice::query()->where('generation_source','AUTOMATION_RECOVERY')
            ->whereDate('recurring_cycle_date',$dueDate)->whereDate('billing_period_start',$periodStart)->count();

        return [
            'mode'=>$execute?'execute':'audit','timezone'=>$timezone,
            'recovery_scope'=>'November 5, 2026 recurring cycle only',
            'scheduled_generation_date'=>$scheduledDate->toDateString(),'due_date'=>$dueDate->toDateString(),
            'billing_period'=>[$periodStart->toDateString(),$dueDate->toDateString()],
            'eligible_customers'=>$customers->count(),'missing_invoices'=>$missingCount,
            'existing_invoices'=>$existingCount,'created_invoices'=>$createdCount,
            'verified_recovery_invoices'=>$verified,'verification_passed'=>count($errors)===0,
            'future_cycles_touched'=>0,'errors'=>$errors,'details'=>$details,
        ];
    }

    private function existingCycleInvoice(string $customerId,Carbon $dueDate,Carbon $periodStart,bool $lock=false): ?Invoice
    {
        $query=Invoice::query()->where('customer_id',$customerId)
            ->whereNotIn('status',Invoice::NON_FULFILLING_CYCLE_STATUSES)
            ->where(function($query)use($dueDate,$periodStart){
                $query->whereDate('recurring_cycle_date',$dueDate)
                    ->orWhere(function($query)use($dueDate,$periodStart){
                        $query->whereDate('due_date',$dueDate)
                            ->whereDate('billing_period_start',$periodStart)
                            ->whereDate('billing_period_end',$dueDate);
                    });
            })->orderBy('created_at');
        return ($lock?$query->lockForUpdate():$query)->first();
    }
}
