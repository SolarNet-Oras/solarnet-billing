<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\FinancialMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialMonitoringController extends Controller
{
    /**
     * Role-gated by the route. This endpoint is intentionally read-only and
     * does not expose a financial adjustment or AI write operation.
     */
    public function index(Request $request, FinancialMonitoringService $monitoring): JsonResponse
    {
        $data = $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        return response()->json([
            'data' => $monitoring->summary($data['month'] ?? null),
        ]);
    }

    /** Restore an accidentally archived customer without altering any invoice. */
    public function restoreArchivedCustomer(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only the Super Administrator can correct archived customers.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $customer = Customer::withTrashed()->findOrFail($id);
        abort_unless($customer->trashed(), 422, 'This customer is not archived.');

        $auditNote = sprintf(
            '[Archive correction %s by %s] Customer restored. Reason: %s',
            now()->toDateTimeString(),
            $request->user()->name,
            trim($data['reason'])
        );
        $customer->notes = trim(($customer->notes ? $customer->notes."\n" : '').$auditNote);
        $customer->save();
        $customer->restore();

        return response()->json(['message' => 'Customer restored. Existing invoices were preserved for review.']);
    }
}
