<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\IpIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IpIntelligenceController extends Controller
{
    public function lookup(Request $request, IpIntelligenceService $service): JsonResponse
    {
        $data = $request->validate(['ip' => ['required', 'string', 'max:45']]);
        return response()->json(['data' => $service->lookup($data['ip'])]);
    }
}
