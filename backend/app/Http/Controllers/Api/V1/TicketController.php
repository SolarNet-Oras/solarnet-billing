<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TicketController extends Controller
{
    public function __construct(protected TicketService $ticketService, protected TicketWorkflowService $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $query = Ticket::with(['customer.servicePlan', 'assignedTechnician', 'comments', 'histories.user']);
        if ($request->filled('status')) $query->where('workflow_status', $request->status);
        if ($request->filled('ticket_type')) $query->where('ticket_type', $request->ticket_type);
        if ($request->filled('priority')) $query->where('priority', $request->priority);
        if ($request->filled('category')) $query->where('category', $request->category);
        if ($request->filled('assigned_to')) $query->where('assigned_to', $request->assigned_to);
        if ($request->boolean('unassigned')) $query->whereNull('assigned_to');
        $tickets = $query->latest()->paginate(min((int) $request->get('per_page', 15), 100));
        $tickets->getCollection()->each(function (Ticket $ticket): void {
            if ($ticket->ticket_type === 'installation' && $ticket->workflow_status === 'waiting_admin_approval') {
                $ticket->setAttribute('installation_validation', $this->workflow->installationValidation($ticket));
            }
        });

        return response()->json($tickets);
    }

    public function show(string $id): JsonResponse
    {
        $ticket = Ticket::with(['customer.servicePlan', 'assignedTechnician', 'comments.user', 'comments.customer', 'histories.user'])->findOrFail($id);
        if ($ticket->ticket_type === 'installation' && $ticket->workflow_status === 'waiting_admin_approval') {
            $ticket->setAttribute('installation_validation', $this->workflow->installationValidation($ticket));
        }
        return response()->json($ticket);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|uuid|exists:customers,id', 'subject' => 'required|string|max:255',
            'description' => 'required|string', 'priority' => 'nullable|in:low,medium,high,urgent',
            'category' => 'nullable|in:technical,billing,general,network_issue',
        ]);
        if ($validator->fails()) return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        return response()->json(['message' => 'Ticket created successfully', 'ticket' => $this->ticketService->createTicket($request->all())], 201);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|uuid|exists:users,id']);
        return response()->json(['message' => 'Ticket assigned successfully', 'ticket' => $this->ticketService->assignTicket(Ticket::findOrFail($id), $data['user_id'])]);
    }

    public function claimInstallation(Request $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Installation application claimed.', 'ticket' => $this->workflow->claimInstallation(Ticket::findOrFail($id), $request->user())]);
    }

    public function submitInstallation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['mac_address' => ['required', 'string', 'max:32'], 'notes' => ['required', 'string', 'max:2000']]);
        return response()->json(['message' => 'Installation submitted for administrator review.', 'ticket' => $this->workflow->submitInstallation(Ticket::findOrFail($id), $request->user(), $data)]);
    }

    public function markRepairIn(Request $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Repair marked in and is now in progress.', 'ticket' => $this->workflow->markRepairIn(Ticket::findOrFail($id), $request->user())]);
    }

    public function resolveRepair(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'resolution_notes' => ['required', 'string', 'min:5', 'max:4000'],
            'findings' => ['nullable', 'string', 'max:2000'], 'actions_performed' => ['nullable', 'string', 'max:2000'],
            'equipment_replaced' => ['nullable', 'string', 'max:2000'], 'materials_used' => ['nullable', 'string', 'max:2000'],
        ]);
        return response()->json(['message' => 'Repair resolved.', 'ticket' => $this->workflow->resolveRepair(Ticket::findOrFail($id), $request->user(), $data)]);
    }

    public function closeRepair(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        return response()->json(['message' => 'Repair ticket closed.', 'ticket' => $this->workflow->closeRepair(Ticket::findOrFail($id), $request->user(), $data['notes'] ?? null)]);
    }

    public function approveInstallation(Request $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Installation approved and customer registered.', 'data' => $this->workflow->approveInstallation(Ticket::findOrFail($id), $request->user())]);
    }

    public function returnInstallation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        return response()->json(['message' => 'Installation returned to technician for correction.', 'ticket' => $this->workflow->returnInstallation(Ticket::findOrFail($id), $request->user(), $data['reason'])]);
    }

    public function addComment(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['comment' => 'required|string', 'is_internal' => 'nullable|boolean']);
        $ticket = Ticket::findOrFail($id);
        $comment = $this->ticketService->addComment($ticket, ['comment' => $data['comment'], 'is_internal' => $request->boolean('is_internal'), 'user_id' => $request->user()->id]);
        return response()->json(['message' => 'Comment added successfully', 'comment' => $comment->load(['user', 'customer']), 'ticket' => $ticket->fresh(['customer', 'assignedTechnician', 'comments', 'histories.user'])], 201);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:open,in_progress,resolved,closed']);
        $ticket = Ticket::findOrFail($id);
        if (in_array($ticket->ticket_type, ['repair', 'installation'], true)) return response()->json(['message' => 'Use the validated workflow action for repair or installation tickets.'], 422);
        return response()->json(['message' => 'Ticket status updated successfully', 'ticket' => $this->ticketService->updateStatus($ticket, $data['status'])]);
    }

    public function statistics(): JsonResponse
    {
        return response()->json([
            'total' => Ticket::count(), 'open' => Ticket::where('status', 'open')->count(),
            'in_progress' => Ticket::where('status', 'in_progress')->count(), 'resolved' => Ticket::where('status', 'resolved')->count(),
            'closed' => Ticket::where('status', 'closed')->count(),
            'urgent' => Ticket::where('priority', 'urgent')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'unassigned' => Ticket::whereNull('assigned_to')->whereNotIn('status', ['resolved', 'closed'])->count(),
        ]);
    }
}
