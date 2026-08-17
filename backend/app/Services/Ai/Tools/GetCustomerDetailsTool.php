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
                    'next_due_date' => $unpaidInvoices->first()?->due_date?->toDateString(),
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
}
