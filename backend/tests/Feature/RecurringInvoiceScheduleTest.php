<?php

namespace Tests\Feature;

use App\Jobs\SendInitialInvoiceEmail;
use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecurringInvoiceScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendInitialInvoiceEmail::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_the_cycle_due_exactly_seven_days_later_is_generated(): void
    {
        $dueInEight = $this->customer('Due in 8', '1000000001', '2026-01-01', 20);
        $dueInSeven = $this->customer('Due in 7', '1000000002', '2026-01-01', 19);
        $dueInSix = $this->customer('Due in 6', '1000000003', '2026-01-01', 18);
        $dueToday = $this->customer('Due today', '1000000004', '2026-01-01', 12);
        $passed = $this->customer('Passed', '1000000005', '2026-01-01', 11);

        $this->runScheduler('2026-10-12');

        $invoice = Invoice::where('customer_id', $dueInSeven->id)->sole();
        $this->assertSame('2026-10-19', $invoice->due_date->toDateString());
        foreach ([$dueInEight, $dueInSix, $dueToday, $passed] as $customer) {
            $this->assertDatabaseMissing('invoices', ['customer_id' => $customer->id]);
        }
    }

    public function test_repeat_and_next_day_runs_do_not_duplicate_or_backfill(): void
    {
        $customer = $this->customer('Exact Customer', '1000000010', '2026-01-01', 19);

        $this->runScheduler('2026-10-12');
        $this->runScheduler('2026-10-12');
        $this->runScheduler('2026-10-13');

        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
        $invoice = Invoice::where('customer_id', $customer->id)->sole();
        $this->assertSame('2026-10-19', $invoice->recurring_cycle_date->toDateString());
        $this->assertSame('2026-10-12', $invoice->scheduled_generation_date->toDateString());
    }

    public function test_manual_invoice_for_the_same_period_prevents_automatic_duplicate(): void
    {
        $customer = $this->customer('Manual Cycle', '1000000011', '2026-01-01', 19);
        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'MANUAL-202610-0001',
            'issue_date' => '2026-10-12',
            'due_date' => '2026-10-19',
            'billing_period_start' => '2026-09-19',
            'billing_period_end' => '2026-10-19',
            'generation_source' => 'manual',
            'total' => 800,
            'balance' => 800,
            'status' => 'sent',
        ]);

        $this->runScheduler('2026-10-12');
        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
    }

    public function test_cancelled_accidental_cycle_does_not_block_the_correct_recurring_statement(): void
    {
        $customer = $this->customer('Cancelled Cycle', '1000000021', '2026-01-01', 19);
        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'SLR-CANCELLED-0001',
            'issue_date' => '2026-10-12',
            'due_date' => '2026-10-19',
            'billing_period_start' => '2026-09-19',
            'billing_period_end' => '2026-10-19',
            'recurring_cycle_date' => '2026-10-19',
            'generation_source' => 'manual',
            'total' => 800,
            'balance' => 800,
            'status' => 'cancelled',
        ]);

        $this->runScheduler('2026-10-12');

        $this->assertSame(2, Invoice::where('customer_id', $customer->id)->count());
        $replacement = Invoice::where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->sole();
        $this->assertSame('recurring', $replacement->generation_source);
        $this->assertSame('2026-10-19', $replacement->recurring_cycle_date->toDateString());
    }

    public function test_fully_discounted_prior_cycle_does_not_create_credit_or_block_the_next_cycle(): void
    {
        $customer = $this->customer('Fully Discounted', '1000000023', '2026-01-01', 19);
        $prior = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'SLR-FULL-DISCOUNT-0001',
            'issue_date' => '2026-09-12',
            'due_date' => '2026-09-19',
            'billing_period_start' => '2026-08-19',
            'billing_period_end' => '2026-09-19',
            'recurring_cycle_date' => '2026-09-19',
            'generation_source' => 'recurring',
            'discount' => 800,
            'paid_amount' => 0,
            'status' => 'sent',
        ]);
        $prior->items()->create(['description' => 'Monthly service', 'quantity' => 1, 'unit_price' => 800, 'total' => 800]);
        app(InvoiceService::class)->calculateInvoiceTotals($prior);

        $this->assertSame('0.00', $prior->fresh()->total);
        $this->assertSame('0.00', $prior->fresh()->balance);
        $this->assertDatabaseCount('customer_credits', 0);

        $this->runScheduler('2026-10-12');

        $this->assertDatabaseCount('customer_credits', 0);
        $this->assertDatabaseHas('invoices', [
            'customer_id' => $customer->id,
            'recurring_cycle_date' => '2026-10-19',
            'generation_source' => 'recurring',
        ]);
    }

    public function test_paid_manual_invoice_outside_the_service_period_does_not_block_recurring_cycle(): void
    {
        $customer = $this->customer('Manual Paid Other Period', '1000000024', '2026-01-01', 19);
        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'MANUAL-PAID-OUTSIDE-0001',
            'issue_date' => '2026-10-10',
            'due_date' => '2026-10-19',
            'billing_period_start' => '2026-10-10',
            'billing_period_end' => '2026-10-10',
            'generation_source' => 'manual',
            'subtotal' => 500,
            'total' => 500,
            'paid_amount' => 500,
            'balance' => 0,
            'status' => 'paid',
        ]);

        $this->runScheduler('2026-10-12');

        $this->assertSame(2, Invoice::where('customer_id', $customer->id)->count());
        $this->assertDatabaseHas('invoices', [
            'customer_id' => $customer->id,
            'recurring_cycle_date' => '2026-10-19',
            'generation_source' => 'recurring',
        ]);
    }

    public function test_prior_late_discounted_invoice_never_becomes_advance_or_blocks_next_cycle(): void
    {
        $customer = $this->customer('Discounted Customer', '1000000022', '2026-01-01', 19);
        $prior = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'SLR-DISCOUNT-0001',
            'issue_date' => '2026-09-12',
            'due_date' => '2026-09-19',
            'billing_period_start' => '2026-08-19',
            'billing_period_end' => '2026-09-19',
            'recurring_cycle_date' => '2026-09-19',
            'generation_source' => 'recurring',
            'discount' => 200,
            'paid_amount' => 0,
            'status' => 'overdue',
        ]);
        $prior->items()->create(['description' => 'Monthly service', 'quantity' => 1, 'unit_price' => 800, 'total' => 800]);
        app(InvoiceService::class)->calculateInvoiceTotals($prior);

        $this->assertSame('600.00', $prior->fresh()->balance);
        $this->assertDatabaseCount('customer_credits', 0);

        $this->runScheduler('2026-10-12');

        $this->assertDatabaseCount('customer_credits', 0);
        $this->assertDatabaseHas('invoices', [
            'customer_id' => $customer->id,
            'recurring_cycle_date' => '2026-10-19',
            'generation_source' => 'recurring',
        ]);
    }

    public function test_inactive_and_non_billable_customers_are_skipped(): void
    {
        $inactive = $this->customer('Inactive', '1000000012', '2026-01-01', 19);
        $inactive->update(['status' => 'pending']);
        $free = $this->customer('No Amount', '1000000013', '2026-01-01', 19, 0);

        $this->runScheduler('2026-10-12');

        $this->assertDatabaseMissing('invoices', ['customer_id' => $inactive->id]);
        $this->assertDatabaseMissing('invoices', ['customer_id' => $free->id]);
    }

    public function test_suspended_subscription_continues_monthly_billing_until_expired(): void
    {
        $suspended = $this->customer('Suspended Subscriber', '1000000019', '2026-01-01', 19);
        $suspended->update(['status' => 'suspended']);
        $expired = $this->customer('Expired Subscriber', '1000000020', '2026-01-01', 19);
        $expired->update(['status' => 'expired']);

        $this->runScheduler('2026-10-12');

        $invoice = Invoice::where('customer_id', $suspended->id)->sole();
        $this->assertSame('2026-10-19', $invoice->due_date->toDateString());
        $this->assertSame('recurring', $invoice->generation_source);
        $this->assertDatabaseMissing('invoices', ['customer_id' => $expired->id]);
    }

    public function test_month_end_and_leap_year_calendar_rules(): void
    {
        $customer = $this->customer('Leap Customer', '1000000014', '2027-01-31', 31);
        $this->runScheduler('2028-02-22');
        $invoice = Invoice::where('customer_id', $customer->id)->sole();
        $this->assertSame('2028-02-29', $invoice->due_date->toDateString());
    }

    public function test_one_second_before_midnight_uses_the_current_manila_date(): void
    {
        $dueEighteenth = $this->customer('Before Midnight', '1000000015', '2026-01-01', 18);
        $dueNineteenth = $this->customer('After Midnight', '1000000016', '2026-01-01', 19);

        $this->travelTo(Carbon::create(2026, 10, 11, 23, 59, 59, 'Asia/Manila'));
        $this->artisan('automation:generate-recurring-invoices')->assertSuccessful();
        $this->assertSame(
            '2026-10-11',
            AutomationLog::latest('started_at')->firstOrFail()->summary['run_date']
        );
        $invoice = Invoice::where('customer_id', $dueEighteenth->id)->sole();
        $this->assertSame('2026-10-18', $invoice->due_date->toDateString());
        $this->assertDatabaseMissing('invoices', ['customer_id' => $dueNineteenth->id]);
    }

    public function test_scheduled_run_after_midnight_uses_the_new_manila_date(): void
    {
        $dueEighteenth = $this->customer('Previous Date', '1000000017', '2026-01-01', 18);
        $dueNineteenth = $this->customer('After Midnight', '1000000018', '2026-01-01', 19);

        // Production runs this command at 00:05 Asia/Manila.
        $this->travelTo(Carbon::create(2026, 10, 12, 0, 5, 0, 'Asia/Manila'));
        $this->artisan('automation:generate-recurring-invoices')->assertSuccessful();
        $this->assertSame(
            '2026-10-12',
            AutomationLog::latest('started_at')->firstOrFail()->summary['run_date']
        );
        $invoice = Invoice::where('customer_id', $dueNineteenth->id)->sole();
        $this->assertSame('2026-10-19', $invoice->due_date->toDateString());
        $this->assertDatabaseMissing('invoices', ['customer_id' => $dueEighteenth->id]);
    }

    public function test_all_scheduler_children_run_in_foreground_for_reliable_reaping(): void
    {
        $events = collect(app(Schedule::class)->events());
        $recurring = $events
            ->first(fn ($event) => str_contains($event->command ?? '', 'automation:generate-recurring-invoices'));

        $this->assertNotNull($recurring);
        $this->assertGreaterThan(10, $events->count());
        $events->each(function ($event): void {
            $this->assertFalse(
                $event->runInBackground,
                'Scheduled command must run in the foreground: '.($event->command ?? 'unknown'),
            );
        });
    }

    public function test_customer_generation_error_is_logged_and_returns_failure_exit_code(): void
    {
        $this->customer('Generation Failure', '1000000025', '2026-01-01', 19);
        $this->mock(InvoiceService::class, function ($mock): void {
            $mock->shouldReceive('generateInvoice')
                ->once()
                ->andThrow(new \RuntimeException('Synthetic generation failure'));
        });

        $this->artisan('automation:generate-recurring-invoices', [
            '--date' => '2026-10-12',
            '--triggered-by' => 'manual',
        ])->assertFailed();

        $log = AutomationLog::latest('started_at')->firstOrFail();
        $this->assertSame(AutomationLog::STATUS_PARTIAL, $log->status);
        $this->assertSame('Synthetic generation failure', $log->summary['errors'][0]['error']);
        $this->assertDatabaseCount('invoices', 0);
    }

    private function runScheduler(string $date): void
    {
        $this->artisan('automation:generate-recurring-invoices', [
            '--date' => $date,
            '--triggered-by' => 'manual',
        ])->assertSuccessful();
    }

    private function customer(string $name, string $account, string $installed, int $cycleDay, float $fee = 800): Customer
    {
        return Customer::create([
            'account_number' => $account,
            'full_name' => $name,
            'address' => 'Test address',
            'contact_number' => '09170000000',
            'installation_date' => $installed,
            'billing_cycle_day' => $cycleDay,
            'monthly_fee' => $fee,
            'status' => 'active',
        ]);
    }
}
