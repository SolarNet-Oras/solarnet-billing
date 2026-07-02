<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Router;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * HSGQ OLT integration endpoints.
 *
 * OLT device records are stored in the routers table with `device_type = 'olt'`.
 * ONT discovery / statistics require live SNMP/telnet calls to the OLT and are
 * marked as "not_implemented" until the SNMP integration layer is added.
 */
class HsgqOltController extends Controller
{
    /** List HSGQ OLT devices (real records from DB). */
    public function index(): JsonResponse
    {
        // Only routers explicitly tagged as OLT via the notes field or a
        // future dedicated column. Until the schema has a `device_type` column,
        // we look for routers whose name/notes include "OLT" or "HSGQ".
        $devices = Router::where(function ($q) {
            $q->where('name', 'ILIKE', '%olt%')
              ->orWhere('name', 'ILIKE', '%hsgq%')
              ->orWhere('notes', 'ILIKE', '%olt%');
        })
        ->orderBy('name')
        ->get()
        ->map(function (Router $r) {
            return [
                'id'                => $r->id,
                'name'              => $r->name,
                'ip_address'        => $r->host,
                'model'             => $r->routeros_version ?? 'Unknown',
                'status'            => $r->connection_status ?? 'unknown',
                'firmware_version'  => $r->routeros_version,
                'last_connected_at' => $r->last_connected_at,
                'location'          => $r->location,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $devices,
        ]);
    }

    /**
     * List ONTs connected to an OLT.
     * TODO: implement SNMP polling to HSGQ OLT to enumerate ONTs.
     * For now return an empty list rather than fabricated demo data.
     */
    public function getOnts(string $oltId): JsonResponse
    {
        $olt = Router::find($oltId);
        if (!$olt) {
            return response()->json(['success' => false, 'message' => 'OLT not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [],
            'notice'  => 'ONT enumeration requires the SNMP integration layer (not yet implemented). Configure SNMP credentials on the OLT device to enable this feature.',
        ]);
    }

    /**
     * Trigger ONT discovery on the OLT.
     * Requires SNMP integration — returns a clear "not implemented" response.
     */
    public function discoverOnts(Request $request, string $oltId): JsonResponse
    {
        $olt = Router::find($oltId);
        if (!$olt) {
            return response()->json(['success' => false, 'message' => 'OLT not found'], 404);
        }

        Log::info('ONT discovery requested', ['olt_id' => $oltId]);

        return response()->json([
            'success' => false,
            'message' => 'ONT discovery not implemented yet. This endpoint will run a real SNMP/CLI discovery on the OLT once the integration layer is added.',
            'code'    => 'NOT_IMPLEMENTED',
        ], 501);
    }

    /**
     * Authorize an ONT with a line/service profile.
     * TODO: send actual authorization command to the OLT.
     */
    public function authorizeOnt(Request $request, string $oltId, string $ontId): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'line_profile'    => 'required|string',
            'service_profile' => 'required|string',
            'vlan'            => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $olt = Router::find($oltId);
        if (!$olt) {
            return response()->json(['success' => false, 'message' => 'OLT not found'], 404);
        }

        Log::info('ONT authorization requested', [
            'olt_id'  => $oltId,
            'ont_id'  => $ontId,
            'profile' => $request->line_profile,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'ONT authorization not implemented yet. Requires OLT SNMP/CLI integration.',
            'code'    => 'NOT_IMPLEMENTED',
        ], 501);
    }

    /**
     * Reboot an ONT (not yet implemented).
     */
    public function rebootOnt(string $oltId, string $ontId): JsonResponse
    {
        $olt = Router::find($oltId);
        if (!$olt) {
            return response()->json(['success' => false, 'message' => 'OLT not found'], 404);
        }

        Log::info('ONT reboot requested', ['olt_id' => $oltId, 'ont_id' => $ontId]);

        return response()->json([
            'success' => false,
            'message' => 'ONT reboot not implemented yet. Requires OLT SNMP/CLI integration.',
            'code'    => 'NOT_IMPLEMENTED',
        ], 501);
    }

    /**
     * Get ONT statistics (not yet implemented).
     */
    public function getOntStatistics(string $oltId, string $ontId): JsonResponse
    {
        $olt = Router::find($oltId);
        if (!$olt) {
            return response()->json(['success' => false, 'message' => 'OLT not found'], 404);
        }

        return response()->json([
            'success' => false,
            'message' => 'ONT statistics not implemented yet. Requires OLT SNMP integration.',
            'code'    => 'NOT_IMPLEMENTED',
        ], 501);
    }
}
