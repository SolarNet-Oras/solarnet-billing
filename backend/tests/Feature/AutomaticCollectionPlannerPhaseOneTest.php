<?php

namespace Tests\Feature;

use App\Models\CollectionPlan;
use App\Models\CollectionPlannerRun;
use App\Models\CollectorCustomerAssignment;
use App\Models\CollectorTerritory;
use App\Models\Customer;
use App\Models\CustomerCollectionPreference;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AutomaticCollectionPlannerPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-04 06:30:00', 'Asia/Manila'));
    }

    public function test_automatic_execution_is_inert_until_both_safety_gates_are_enabled(): void
    {
        config(['collection_planner.automation_enabled' => false]);
        Setting::put('collection_planner.auto_enabled', true, 'bool');

        $this->artisan('collection:generate-daily-plans', [
            '--execute' => true,
            '--triggered-by' => 'schedule',
        ])->assertSuccessful();

        $this->assertDatabaseCount('collection_planner_runs', 0);
        $this->assertDatabaseCount('collector_customer_assignments', 0);
        $this->assertDatabaseCount('collection_plans', 0);
    }

    public function test_audit_discovers_manual_and_recurring_invoices_without_writing_plans(): void
    {
        $collector = $this->collector('Eastern Collector');
        $this->territory($collector, 'Eastern Samar fallback', null, true);

        $manual = $this->customer('Manual Billing Client', 'Phase A, Barangay Cadi-an');
        $recurring = $this->customer('Recurring Billing Client', 'Phase B, Barangay Cadi-an');
        $cancelled = $this->customer('Cancelled Billing Client', 'Phase C, Barangay Cadi-an');
        $this->invoice($manual, 'MANUAL-001', '2026-11-03', 'manual');
        $this->invoice($recurring, 'SLR-RECURRING-001', '2026-11-04', 'recurring');
        $this->invoice($cancelled, 'SLR-CANCELLED-001', '2026-11-04', 'recurring', 'cancelled');

        $this->artisan('collection:generate-daily-plans', [
            '--date' => '2026-11-04',
            '--dry-run' => true,
        ])->assertSuccessful();

        $run = CollectionPlannerRun::sole();
        $this->assertTrue($run->dry_run);
        $this->assertSame(2, $run->eligible_customers);
        $this->assertSame(2, $run->new_assignments);
        $this->assertSame(0, $run->unassigned_exceptions);
        $this->assertDatabaseCount('collector_customer_assignments', 0);
        $this->assertDatabaseCount('collection_plans', 0);
    }

    public function test_enabled_execution_assigns_by_territory_and_is_idempotent(): void
    {
        config(['collection_planner.automation_enabled' => true]);
        Setting::put('collection_planner.auto_enabled', true, 'bool');
        $northCollector = $this->collector('North Collector');
        $fallbackCollector = $this->collector('Fallback Collector');
        $this->territory($northCollector, 'Barangay Cadi-an', 'cadi-an');
        $this->territory($fallbackCollector, 'Unmatched addresses', null, true);

        $north = $this->customer('North Client', 'Phase G, Barangay Cadi-an');
        $other = $this->customer('Other Client', 'Barangay Factoria');
        $upcoming = $this->customer('Upcoming Client', 'Barangay Factoria');
        CustomerCollectionPreference::create([
            'customer_id' => $upcoming->id,
            'visit_allowed' => true,
            'early_collection_allowed' => true,
        ]);
        $this->invoice($north, 'AUTO-001', '2026-11-03', 'manual');
        $this->invoice($other, 'AUTO-002', '2026-11-04', 'recurring');
        $this->invoice($upcoming, 'AUTO-003', '2026-11-10', 'recurring');

        $arguments = [
            '--execute' => true,
            '--confirm' => 'GENERATE DAILY COLLECTION PLANS',
            '--date' => '2026-11-04',
        ];
        $this->artisan('collection:generate-daily-plans', $arguments)->assertSuccessful();
        $this->artisan('collection:generate-daily-plans', $arguments)->assertSuccessful();

        $this->assertDatabaseCount('collection_planner_runs', 1);
        $this->assertDatabaseCount('collector_customer_assignments', 3);
        $this->assertDatabaseCount('collection_plans', 2);
        $this->assertDatabaseCount('collection_plan_stops', 3);
        $this->assertSame($northCollector->id, CollectorCustomerAssignment::where('customer_id', $north->id)->value('collector_id'));
        $this->assertSame($fallbackCollector->id, CollectorCustomerAssignment::where('customer_id', $other->id)->value('collector_id'));

        $run = CollectionPlannerRun::sole();
        $this->assertSame('success', $run->status);
        $this->assertSame(3, $run->eligible_customers);
        $this->assertSame(3, $run->scheduled_visits);
        $this->assertSame(2, $run->plans_generated);
        $this->assertTrue(CollectionPlan::query()->where('generation_mode', 'automatic')->where('collection_planner_run_id', $run->id)->count() === 2);
    }

    public function test_existing_authorized_assignment_is_preserved_and_capacity_is_reported(): void
    {
        config(['collection_planner.automation_enabled' => true]);
        Setting::put('collection_planner.auto_enabled', true, 'bool');
        $collector = $this->collector('Limited Collector');
        $this->territory($collector, 'Only route', null, true, 1);
        $existing = $this->customer('Existing Assignment', 'Barangay A');
        $overflow = $this->customer('Capacity Overflow', 'Barangay B');
        CollectorCustomerAssignment::create([
            'collector_id' => $collector->id,
            'customer_id' => $existing->id,
            'assigned_at' => now(),
        ]);
        $this->invoice($existing, 'CAPACITY-001', '2026-11-04', 'manual');
        $this->invoice($overflow, 'CAPACITY-002', '2026-11-04', 'manual');

        $this->artisan('collection:generate-daily-plans', [
            '--execute' => true,
            '--confirm' => 'GENERATE DAILY COLLECTION PLANS',
            '--date' => '2026-11-04',
        ])->assertSuccessful();

        $run = CollectionPlannerRun::sole();
        $this->assertSame(1, $run->unassigned_exceptions);
        $this->assertSame('territory_capacity_reached', $run->summary['assignment_exceptions'][0]['reason']);
        $this->assertSame($collector->id, CollectorCustomerAssignment::where('customer_id', $existing->id)->value('collector_id'));
        $this->assertDatabaseMissing('collector_customer_assignments', ['customer_id' => $overflow->id]);
    }

    public function test_daily_planner_schedule_runs_in_foreground_with_overlap_protection(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'collection:generate-daily-plans'));

        $this->assertNotNull($event);
        $this->assertFalse($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--execute', $event->command);
        $this->assertStringContainsString('--triggered-by=schedule', $event->command);
    }

    private function collector(string $name): User
    {
        $role = Role::firstOrCreate(['name' => 'collector'], [
            'display_name' => 'Collector',
            'is_active' => true,
        ]);
        $collector = User::factory()->create(['name' => $name, 'is_active' => true]);
        $collector->roles()->attach($role);

        return $collector;
    }

    private function territory(
        User $collector,
        string $name,
        ?string $pattern,
        bool $fallback = false,
        int $capacity = 24,
    ): CollectorTerritory {
        return CollectorTerritory::create([
            'collector_id' => $collector->id,
            'name' => $name,
            'address_pattern' => $pattern,
            'is_fallback' => $fallback,
            'is_active' => true,
            'priority' => 100,
            'max_daily_visits' => $capacity,
        ]);
    }

    private function customer(string $name, string $address): Customer
    {
        return Customer::create([
            'account_number' => (string) random_int(1000000000, 9999999999),
            'full_name' => $name,
            'address' => $address,
            'contact_number' => '09999999999',
            'installation_date' => '2026-01-01',
            'monthly_fee' => '800.00',
            'status' => 'active',
        ]);
    }

    private function invoice(
        Customer $customer,
        string $number,
        string $dueDate,
        string $source,
        string $status = 'sent',
    ): Invoice {
        return Invoice::create([
            'invoice_number' => $number,
            'customer_id' => $customer->id,
            'issue_date' => $dueDate,
            'due_date' => $dueDate,
            'billing_period_start' => $dueDate,
            'billing_period_end' => $dueDate,
            'subtotal' => '800.00',
            'tax' => '0.00',
            'discount' => '0.00',
            'total' => '800.00',
            'paid_amount' => '0.00',
            'balance' => '800.00',
            'status' => $status,
            'generation_source' => $source,
        ]);
    }
}
