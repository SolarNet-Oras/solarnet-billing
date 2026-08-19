<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OperationsMapAsset;
use App\Services\OperationsMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsMapController extends Controller
{
    public function index(OperationsMapService $operationsMap): JsonResponse
    {
        return response()->json(['data' => $operationsMap->snapshot()]);
    }

    public function store(Request $request, OperationsMapService $operationsMap): JsonResponse
    {
        $asset = new OperationsMapAsset($this->validatedAsset($request));
        $asset->created_by = $request->user()->id;
        $asset->save();

        return response()->json([
            'message' => 'Map asset saved. This does not change RouterOS, OLT, or customer records.',
            'data' => $operationsMap->snapshot(),
        ], 201);
    }

    public function update(Request $request, OperationsMapAsset $operationsMapAsset, OperationsMapService $operationsMap): JsonResponse
    {
        $operationsMapAsset->fill($this->validatedAsset($request));
        $operationsMapAsset->save();

        return response()->json([
            'message' => 'Map asset updated. This does not change RouterOS, OLT, or customer records.',
            'data' => $operationsMap->snapshot(),
        ]);
    }

    public function destroy(OperationsMapAsset $operationsMapAsset, OperationsMapService $operationsMap): JsonResponse
    {
        $operationsMapAsset->delete();

        return response()->json([
            'message' => 'Map asset removed. Client, RouterOS, OLT, and billing records were not changed.',
            'data' => $operationsMap->snapshot(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedAsset(Request $request): array
    {
        $data = $request->validate([
            'asset_type' => ['required', Rule::in(['nap', 'pole', 'fiber_route'])],
            'name' => ['required', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'route_coordinates' => ['nullable', 'array', 'max:300'],
            'status' => ['nullable', Rule::in(['active', 'planned', 'retired'])],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        if ($data['asset_type'] === 'fiber_route') {
            $data['latitude'] = null;
            $data['longitude'] = null;
            $data['route_coordinates'] = $this->normalizeRouteCoordinates($data['route_coordinates'] ?? null);
        } else {
            $validator = Validator::make($data, [
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
            ]);
            if ($validator->fails()) throw new ValidationException($validator);
            $data['route_coordinates'] = null;
        }

        $data['status'] = $data['status'] ?? 'active';
        return $data;
    }

    /** @return array<int, array{latitude: float, longitude: float}> */
    private function normalizeRouteCoordinates(mixed $coordinates): array
    {
        if (!is_array($coordinates) || count($coordinates) < 2) {
            throw ValidationException::withMessages(['route_coordinates' => 'A fiber route needs at least two verified coordinate points.']);
        }

        $points = [];
        foreach ($coordinates as $index => $point) {
            if (!is_array($point) || !is_numeric($point['latitude'] ?? null) || !is_numeric($point['longitude'] ?? null)
                || (float) $point['latitude'] < -90 || (float) $point['latitude'] > 90
                || (float) $point['longitude'] < -180 || (float) $point['longitude'] > 180) {
                throw ValidationException::withMessages(["route_coordinates.{$index}" => 'Each fiber-route point needs a valid latitude and longitude.']);
            }
            $points[] = ['latitude' => (float) $point['latitude'], 'longitude' => (float) $point['longitude']];
        }

        return $points;
    }
}
