<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketComment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TicketService
{
    /**
     * Generate unique ticket number
     */
    public function generateTicketNumber(): string
    {
        $prefix = 'TKT-' . now()->format('Ym') . '-';
        $lastTicket = Ticket::where('ticket_number', 'like', $prefix . '%')
                           ->orderBy('ticket_number', 'desc')
                           ->first();

        if ($lastTicket) {
            $lastNumber = (int) substr($lastTicket->ticket_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Create a new ticket
     */
    public function createTicket(array $data): Ticket
    {
        return DB::transaction(function () use ($data) {
            $ticket = Ticket::create([
                'ticket_number' => $this->generateTicketNumber(),
                'customer_id' => $data['customer_id'],
                'subject' => $data['subject'],
                'description' => $data['description'],
                'priority' => $data['priority'] ?? 'medium',
                'category' => $data['category'] ?? 'general',
                'status' => 'open',
                'ticket_type' => $this->inferType($data['subject'], $data['category'] ?? 'general'),
                'workflow_status' => $this->inferType($data['subject'], $data['category'] ?? 'general') === 'installation' ? 'unclaimed' : 'open',
            ]);

            app(TicketWorkflowService::class)->history($ticket, auth()->user(), 'ticket_created', null, $ticket->workflow_status);

            return $ticket->fresh(['customer', 'assignedTechnician', 'comments']);
        });
    }

    /**
     * Assign ticket to user
     */
    public function assignTicket(Ticket $ticket, string $userId): Ticket
    {
        $previous = $ticket->workflow_status;
        $ticket->update([
            'assigned_to' => $userId,
            'status' => $ticket->ticket_type === 'repair' ? 'open' : $ticket->status,
            'workflow_status' => $ticket->ticket_type === 'repair' ? 'open' : $ticket->workflow_status,
        ]);

        app(TicketWorkflowService::class)->history($ticket, auth()->user(), 'ticket_assigned', $previous, $ticket->workflow_status, null, ['assigned_to' => $userId]);

        return $ticket->fresh(['customer', 'assignedTechnician', 'comments']);
    }

    /**
     * Add comment to ticket
     */
    public function addComment(Ticket $ticket, array $data): TicketComment
    {
        return TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $data['user_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'comment' => $data['comment'],
            'is_internal' => $data['is_internal'] ?? false,
        ]);
    }

    /**
     * Update ticket status
     */
    public function updateStatus(Ticket $ticket, string $status): Ticket
    {
        $previous = $ticket->workflow_status;
        $updateData = ['status' => $status, 'workflow_status' => $status];

        if ($status === 'resolved' && !$ticket->resolved_at) {
            $updateData['resolved_at'] = now();
        } elseif ($status === 'closed' && !$ticket->closed_at) {
            $updateData['closed_at'] = now();
        }

        $ticket->update($updateData);

        app(TicketWorkflowService::class)->history($ticket, auth()->user(), 'status_updated', $previous, $status);

        return $ticket->fresh(['customer', 'assignedTechnician', 'comments']);
    }

    private function inferType(string $subject, string $category): string
    {
        if (str_starts_with($subject, 'New Installation Application')) return 'installation';
        if (in_array($category, ['technical', 'network_issue'], true)) return 'repair';
        return 'other';
    }
}
