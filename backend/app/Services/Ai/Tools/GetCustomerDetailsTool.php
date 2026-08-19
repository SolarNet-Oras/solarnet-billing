<?php

namespace App\Services\Ai\Tools;

use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiTool;

class GetCustomerDetailsTool implements AiTool
{
    public function name(): string { return 'get_customer_details'; }

    public function schema(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_customer_details',
                'description' => 'Return the full record of a single customer, including their service plan, router assignment, and latest invoice status. Pass either the numeric account_number, the UUID id, or a partial name/email match.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'account_number' => ['type' => 'string', 'description' => '10-digit account number.'],
                        'id'             => ['type' => 'string', 'description' => 'Customer UUID.'],
                        'search'         => ['type' => 'string', 'description' => 'Partial name/email match if id/account_number unknown.'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasPermission('view-customers') || $user->hasRole('super_admin');
    }

    public function execute(User $user, array $arguments): array
    {
        $q = Customer::query()->with(['servicePlan', 'router']);
        if (!empty($arguments['id']))             $q->where('id', $arguments['id']);
        elseif (!empty($arguments['account_number'])) $q->where('account_number', $arguments['account_number']);
        elseif (!empty($arguments['search']))     $q->search($arguments['search']);
        else return ['error' => 'Provide at least one of: id, account_number, search.'];

        $customer = $q->first();
        if (!$customer) return ['found' => false];

        $unpaidInvoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->orderBy('due_date')
            ->limit(5)
            ->get();
        $lastPayment = Payment::query()
            ->where('customer_id', $customer->id)
            ->latest('payment_date')
            ->first();
        $lease = DhcpLease::query()
            ->where('customer_id', $customer->id)
            ->where('is_current', true)
            ->latest('last_seen_at')
            ->first();
        $openTicket = Ticket::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['closed', 'resolved'])
            ->latest('created_at')
            ->first();
        $openInvoiceDueDate = $unpaidInvoices->first()?->due_date;
        // Some migrated customers predate installation dates and the later
        // billing-cycle-day field. A previous invoice is a real billing
        // record, so it can safely explain the monthly schedule to the AI
        // without silently writing or altering the customer record.
        $historicalInvoice = Invoice::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('due_date')
            ->latest('due_date')
            ->first(['due_date']);
        $historicalDueDate = $historicalInvoice?->due_date;
        // The installation anniversary is the current billing source of
        // truth. `billing_cycle_day` is only a legacy fallback for records
        // that have no recorded installation date.
        $installationCycleDay = $customer->installation_date?->day;
        $configuredCycleDay = $customer->billingCycleDay();
        $historicalCycleDay = $historicalDueDate?->day;
        $scheduledDueDate = $openInvoiceDueDate
            ? null
            : ($configuredCycleDay
                ? $customer->nextBillingDueDate()
                : ($historicalCycleDay ? $this->nextDueDateForDay($historicalCycleDay) : null));
        $scheduledDueDateSource = $openInvoiceDueDate
            ? 'open_invoice'
            : ($installationCycleDay
                ? 'installation_date_cycle'
                : ($configuredCycleDay
                    ? 'legacy_billing_cycle'
                    : ($historicalCycleDay ? 'historical_invoice_cycle' : 'not_configured')));

        return [
            'found' => true,
            'customer' => [
                'id'                => $customer->id,
                'account_number'    => $customer->account_number,
                'full_name'         => $customer->full_name,
                'email'             => $customer->email,
                'contact_number'    => $customer->contact_number,
                'address'           => $customer->address,
                'status'            => $customer->status,
                'monthly_fee'       => (float) $customer->monthly_fee,
                'installation_date' => optional($customer->installation_date)->toDateString(),
                'mac_address'       => $customer->mac_address,
                'ip_address'        => $customer->ip_address,
                'vlan'              => $customer->vlan ?? null,
                'service_plan'      => $customer->servicePlan ? [
                    'id'             => $customer->servicePlan->id,
                    'name'           => $customer->servicePlan->name,
                    'price'          => (float) $customer->servicePlan->price,
                    'download_speed' => $customer->servicePlan->download_speed,
                    'upload_speed'   => $customer->servicePlan->upload_speed,
                ] : null,
                'router'            => $customer->router ? [
                    'id'                => $customer->router->id,
                    'name'              => $customer->router->name,
                    'connection_status' => $customer->router->connection_status,
                ] : null,
                'billing' => [
                    'outstanding_balance' => round((float) $unpaidInvoices->sum('balance'), 2),
                    'unpaid_invoice_count' => $unpaidInvoices->count(),
                    // Prefer an actual unpaid invoice. When there is no
                    // invoice yet, expose the configured recurring due day so
                    // the AI does not incorrectly claim that an established
                    // migrated customer has no due date.
                    'billing_cycle_day' => $configuredCycleDay ?? $historicalCycleDay,
                    'next_due_date' => ($openInvoiceDueDate ?? $scheduledDueDate)?->toDateString(),
                    'next_due_date_source' => $scheduledDueDateSource,
                    'historical_invoice_due_date' => $historicalDueDate?->toDateString(),
                    'unpaid_invoices' => $unpaidInvoices->map(fn (Invoice $invoice) => [
                        'invoice_number' => $invoice->invoice_number,
                        'due_date' => $invoice->due_date?->toDateString(),
                        'balance' => (float) $invoice->balance,
                        'status' => $invoice->status,
                    ])->values()->all(),
                    'last_payment' => $lastPayment ? [
                        'amount' => (float) $lastPayment->amount,
                        'method' => $lastPayment->payment_method,
                        'payment_date' => $lastPayment->payment_date?->toDateString(),
                        'transaction_id' => $lastPayment->transaction_id,
                    ] : null,
                ],
                'network' => [
                    'current_lease' => $lease ? [
                        'status' => $lease->status,
                        'ip_address' => $lease->ip_address,
                        'last_seen_at' => $lease->last_seen_at?->toIso8601String(),
                    ] : null,
                ],
                'latest_open_ticket' => $openTicket ? [
                    'ticket_number' => $openTicket->ticket_number,
                    'subject' => $openTicket->subject,
                    'status' => $openTicket->status,
                    'priority' => $openTicket->priority,
                ] : null,
                'as_of' => now()->toIso8601String(),
            ],
        ];
    }

    /** Return the next calendar occurrence of a verified historical due day. */
    private function nextDueDateForDay(int $day): \Carbon\Carbon
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $today = now($timezone)->startOfDay();
        $candidate = $today->copy()->startOfMonth()->setDay(min($day, $today->daysInMonth));

        if ($candidate->lt($today)) {
            $nextMonth = $today->copy()->addMonthNoOverflow()->startOfMonth();
            $candidate = $nextMonth->setDay(min($day, $nextMonth->daysInMonth));
        }

        return $candidate;
    }
}
