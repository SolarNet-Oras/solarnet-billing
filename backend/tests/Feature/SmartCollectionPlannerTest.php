<?php

namespace Tests\Feature;

use App\Models\CollectionVisit;
use App\Models\CollectionPlan;
use App\Models\CollectionPlanStop;
use App\Models\CollectionLocationDeferral;
use App\Models\CollectorCustomerAssignment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SmartCollectionPlannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SmartCollectionPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_address_only_customers_are_grouped_and_never_skipped(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 08:00:00', 'Asia/Manila'));
        $collector = User::factory()->create();
        $addressOnly = $this->customer('Address Client', 'Barangay Cadi-an, Oras, Eastern Samar');
        $gps = $this->customer('GPS Client', 'Barangay Cadi-an, Oras, Eastern Samar', ['latitude'=>12.14, 'longitude'=>125.43]);
        foreach ([$addressOnly, $gps] as $customer) {
            CollectorCustomerAssignment::create(['collector_id'=>$collector->id, 'customer_id'=>$customer->id, 'assigned_at'=>now()]);
            $this->invoice($customer, '2026-11-04');
        }

        $rows = app(SmartCollectionPlannerService::class)->eligibleCustomers($collector);

        $this->assertCount(2, $rows);
        $this->assertSame('address_only', $rows->firstWhere('customer.id', $addressOnly->id)['location_source']);
        $this->assertSame('verified_gps', $rows->firstWhere('customer.id', $gps->id)['location_source']);
        $this->assertStringContainsString('Barangay Cadi-An', $rows->firstWhere('customer.id', $addressOnly->id)['address_group']);
    }

    public function test_failed_attempts_lower_priority_without_excluding_customer(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 08:00:00', 'Asia/Manila'));
        $collector = User::factory()->create();
        $first = $this->customer('First Attempt', 'Barangay A');
        $repeat = $this->customer('Repeat Attempt', 'Barangay A');
        foreach ([$first, $repeat] as $customer) {
            CollectorCustomerAssignment::create(['collector_id'=>$collector->id, 'customer_id'=>$customer->id, 'assigned_at'=>now()]);
            $this->invoice($customer, '2026-11-04');
        }
        $plan = CollectionPlan::create(['collector_id'=>$collector->id, 'plan_date'=>'2026-11-03', 'generated_at'=>now()]);
        $stop = CollectionPlanStop::create([
            'collection_plan_id'=>$plan->id, 'customer_id'=>$repeat->id, 'sequence'=>1,
            'priority_level'=>'high', 'priority_score'=>300, 'address_group'=>'Barangay A',
            'location_source'=>'address_only', 'attempt_count'=>0, 'collectible_balance'=>'800.00',
            'earliest_due_date'=>'2026-11-04', 'status'=>'completed',
        ]);
        CollectionVisit::create([
            'collection_plan_stop_id'=>$stop->id, 'collector_id'=>$collector->id,
            'customer_id'=>$repeat->id, 'status'=>'completed', 'outcome'=>'customer_not_home',
            'completed_at'=>now(), 'idempotency_key'=>'repeat-attempt-test',
        ]);

        $rows = app(SmartCollectionPlannerService::class)->eligibleCustomers($collector);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows->firstWhere('customer.id', $repeat->id)['attempt_count']);
        $this->assertGreaterThan($rows->firstWhere('customer.id', $repeat->id)['priority_score'], $rows->firstWhere('customer.id', $first->id)['priority_score']);
    }

    public function test_address_grouping_recognizes_phases_a_through_g_without_coordinates(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 08:00:00', 'Asia/Manila'));
        $collector = User::factory()->create();
        foreach (range('A', 'G') as $phase) {
            $customer = $this->customer("Phase {$phase} Client", "Phase {$phase}, Barangay Cadi-an, Oras");
            CollectorCustomerAssignment::create(['collector_id'=>$collector->id, 'customer_id'=>$customer->id, 'assigned_at'=>now()]);
            $this->invoice($customer, '2026-11-04');
        }

        $groups = app(SmartCollectionPlannerService::class)->eligibleCustomers($collector)->pluck('address_group')->all();

        foreach (range('A', 'G') as $phase) {
            $this->assertContains("Barangay Cadi-An · Phase {$phase}", $groups);
        }
    }

    public function test_unassigned_and_non_collectible_invoices_are_excluded(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 08:00:00', 'Asia/Manila'));
        $collector = User::factory()->create();
        $assigned = $this->customer('Assigned', 'Barangay B');
        $unassigned = $this->customer('Unassigned', 'Barangay B');
        CollectorCustomerAssignment::create(['collector_id'=>$collector->id, 'customer_id'=>$assigned->id, 'assigned_at'=>now()]);
        $this->invoice($assigned, '2026-11-04', 'sent');
        $this->invoice($assigned, '2026-11-03', 'cancelled');
        $this->invoice($unassigned, '2026-11-04', 'sent');

        $rows = app(SmartCollectionPlannerService::class)->eligibleCustomers($collector);

        $this->assertCount(1, $rows);
        $this->assertSame($assigned->id, $rows->first()['customer']->id);
        $this->assertCount(1, $rows->first()['invoices']);
    }

    public function test_coordinate_deferral_keeps_address_only_customer_collectible_and_audited(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 08:00:00', 'Asia/Manila'));
        $collector = User::factory()->create();
        $customer = $this->customer('Address Only', 'Phase B, Barangay Cadi-an');
        CollectorCustomerAssignment::create(['collector_id'=>$collector->id, 'customer_id'=>$customer->id, 'assigned_at'=>now()]);
        $this->invoice($customer, '2026-11-04');

        $this->assertFalse(app(SmartCollectionPlannerService::class)->eligibleCustomers($collector)->first()['location_update_deferred_today']);
        CollectionLocationDeferral::create([
            'customer_id'=>$customer->id,'collector_id'=>$collector->id,
            'context'=>'collection_away_from_service_address',
            'reason'=>'Customer paid at the office, not at the registered service address.',
            'deferred_at'=>now(),
        ]);
        $row=app(SmartCollectionPlannerService::class)->eligibleCustomers($collector)->first();

        $this->assertTrue($row['location_update_deferred_today']);
        $this->assertSame('address_only',$row['location_source']);
        $this->assertTrue($row['plan_eligible']);
    }

    private function customer(string $name, string $address, ?array $gps = null): Customer
    {
        return Customer::create([
            'account_number'=>(string) random_int(1000000000,9999999999), 'full_name'=>$name,
            'address'=>$address, 'contact_number'=>'09999999999', 'installation_date'=>'2026-01-01',
            'monthly_fee'=>'800.00', 'status'=>'active', 'gps_coordinates'=>$gps,
        ]);
    }

    private function invoice(Customer $customer, string $dueDate, string $status = 'sent'): Invoice
    {
        return Invoice::create([
            'invoice_number'=>'PLAN-'.random_int(10000,99999), 'customer_id'=>$customer->id,
            'issue_date'=>$dueDate, 'due_date'=>$dueDate, 'billing_period_start'=>$dueDate,
            'billing_period_end'=>$dueDate, 'subtotal'=>'800.00', 'tax'=>'0.00', 'discount'=>'0.00',
            'total'=>'800.00', 'paid_amount'=>'0.00', 'balance'=>$status === 'cancelled' ? '800.00' : '800.00', 'status'=>$status,
        ]);
    }
}
