<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TicketController extends Controller
{
    protected TicketService $ticketService;

    public function __construct(TicketService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    /**
     * Get all tickets
     */
    public function index(Request $request): JsonResponse
    {
        $query = Ticket::with(['customer', 'assignedTo', 'comments']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filter by assigned user
        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        // Filter unassigned
        if ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to');
        }

        $tickets = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($tickets);
    }

    /**
     * Get single ticket
     */
    public function show(string $id): JsonResponse
    {
        $ticket = Ticket::with(['customer', 'assignedTo', 'comments.user', 'comments.customer'])
                       ->findOrFail($id);

        return response()->json($ticket);
    }

    /**
     * Create new ticket
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|uuid|exists:customers,id',
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'category' => 'nullable|in:technical,billing,general,network_issue',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ticket = $this->ticketService->createTicket($request->all());

        return response()->json([
            'message' => 'Ticket created successfully',
            'ticket' => $ticket,
        ], 201);
    }

    /**
     * Assign ticket
     */
    public function assign(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|uuid|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ticket = Ticket::findOrFail($id);
        $ticket = $this->ticketService->assignTicket($ticket, $request->user_id);

        return response()->json([
            'message' => 'Ticket assigned successfully',
            'ticket' => $ticket,
        ]);
    }

    /** Technicians can claim unassigned installation applications. */
    public function claimInstallation(Request $request, string $id): JsonResponse
    {
        $ticket = Ticket::findOrFail($id);
        if (! str_starts_with($ticket->subject, 'New Installation Application') || $ticket->assigned_to) {
            return response()->json(['message' => 'This installation application is not available to claim.'], 422);
        }
        $ticket->update(['assigned_to' => $request->user()->id, 'status' => 'in_progress']);
        return response()->json(['message' => 'Installation application claimed.', 'ticket' => $ticket->fresh(['customer', 'assignedTo'])]);
    }

    /**
     * Add comment
     */
    public function addComment(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string',
            'is_internal' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ticket = Ticket::findOrFail($id);
        
        // Get authenticated user (would come from auth middleware)
        $commentData = [
            'comment' => $request->comment,
            'is_internal' => $request->boolean('is_internal'),
            'user_id' => $request->user()->id ?? null,
        ];

        $comment = $this->ticketService->addComment($ticket, $commentData);

        return response()->json([
            'message' => 'Comment added successfully',
            'comment' => $comment->load(['user', 'customer']),
            'ticket' => $ticket->fresh(['customer', 'assignedTo', 'comments']),
        ], 201);
    }

    /**
     * Update ticket status
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ticket = Ticket::findOrFail($id);
        $ticket = $this->ticketService->updateStatus($ticket, $request->status);

        return response()->json([
            'message' => 'Ticket status updated successfully',
            'ticket' => $ticket,
        ]);
    }

    /**
     * Get ticket statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total' => Ticket::count(),
            'open' => Ticket::where('status', 'open')->count(),
            'in_progress' => Ticket::where('status', 'in_progress')->count(),
            'resolved' => Ticket::where('status', 'resolved')->count(),
            'closed' => Ticket::where('status', 'closed')->count(),
            'urgent' => Ticket::where('priority', 'urgent')->whereNotIn('status', ['resolved', 'closed'])->count(),
            'unassigned' => Ticket::whereNull('assigned_to')->whereNotIn('status', ['resolved', 'closed'])->count(),
        ];

        return response()->json($stats);
    }
}
