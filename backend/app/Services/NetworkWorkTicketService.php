<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use App\Models\SmsAdvisoryCampaign;
use App\Models\SmsAdvisoryRecipient;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NetworkWorkTicketService
{
    public function __construct(
        protected TicketService $tickets,
        protected TicketWorkflowService $workflow,
        protected PhilSmsService $sms,
    ) {}

    public function create(array $data, User $actor): Ticket
    {
        $router = Router::findOrFail($data['router_id']);
        $recipients = collect();
        if ($data['ticket_type'] === 'maintenance') {
            abort_unless($this->sms->isConfigured(), 422, 'PhilSMS is not configured. No maintenance ticket or advisory was created.');
            $recipients = Customer::where('router_id', $router->id)
                ->get(['id', 'contact_number'])
                ->map(fn (Customer $customer) => [
                    'customer' => $customer,
                    'recipient' => $this->sms->normalisePhilippineMobile((string) $customer->contact_number),
                ])->filter(fn (array $row) => $row['recipient'] !== null)->unique('recipient')->values();
            abort_if($recipients->isEmpty(), 422, 'No customer assigned to this router has a valid Philippine mobile number. Nothing was created.');
        }

        return DB::transaction(function () use ($data, $actor, $router, $recipients): Ticket {
            $campaign = null;
            if ($data['ticket_type'] === 'maintenance') {
                $message = $this->maintenanceMessage($router, $data);
                $campaign = SmsAdvisoryCampaign::create([
                    'created_by' => $actor->id,
                    'title' => 'Maintenance - '.$router->name,
                    'message' => $message,
                    'recipient_filter' => 'all',
                    'router_id' => $router->id,
                    'router_name' => $router->name,
                    'status' => 'queued',
                    'recipient_count' => $recipients->count(),
                ]);
                foreach ($recipients as $row) {
                    SmsAdvisoryRecipient::create([
                        'campaign_id' => $campaign->id,
                        'customer_id' => $row['customer']->id,
                        'recipient' => $row['recipient'],
                        'recipient_last4' => substr($row['recipient'], -4),
                        'status' => 'queued',
                    ]);
                }
            }

            $ticket = Ticket::create([
                'ticket_number' => $this->tickets->generateTicketNumber(),
                'customer_id' => null,
                'router_id' => $router->id,
                'sms_advisory_campaign_id' => $campaign?->id,
                'subject' => $data['subject'],
                'description' => $data['description'],
                'scheduled_start_at' => $data['scheduled_start_at'],
                'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
                'category' => 'network_issue',
                'status' => 'open',
                'ticket_type' => $data['ticket_type'],
                'workflow_status' => 'open',
            ]);
            $this->workflow->history($ticket, $actor, 'ticket_created', null, 'open', null, [
                'router_id' => $router->id,
                'sms_advisory_campaign_id' => $campaign?->id,
                'sms_recipient_count' => $campaign?->recipient_count ?? 0,
            ]);

            return $ticket->fresh(['router', 'smsAdvisoryCampaign', 'assignedTechnician']);
        });
    }

    private function maintenanceMessage(Router $router, array $data): string
    {
        $start = Carbon::parse($data['scheduled_start_at'])->timezone('Asia/Manila')->format('M j, Y g:i A');
        $end = filled($data['scheduled_end_at'] ?? null)
            ? ' Expected completion: '.Carbon::parse($data['scheduled_end_at'])->timezone('Asia/Manila')->format('M j, Y g:i A').'.'
            : '';
        return mb_substr("SOLARNET MAINTENANCE ADVISORY: We sincerely apologize for the possible temporary interruption in the {$router->name} service area on {$start}.{$end} This maintenance helps improve internet reliability and resolve network issues. Thank you for your patience and understanding.", 0, 459);
    }
}
