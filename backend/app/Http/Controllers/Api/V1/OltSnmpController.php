<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OltDevice;
use App\Services\OltSnmpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OltSnmpController extends Controller
{
    public function __construct(private readonly OltSnmpService $snmp)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => OltDevice::query()
                ->with('router:id,name,connection_status,is_active')
                ->orderBy('name')
                ->get()
                ->map->toSafeArray()
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(true));
        if ($validator->fails()) return $this->validationError($validator);

        $olt = OltDevice::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'OLT saved. Run the read-only SNMP test through its selected MikroTik management router.',
            'data' => $olt->load('router:id,name,connection_status,is_active')->toSafeArray(),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $olt = OltDevice::findOrFail($id);
        $validator = Validator::make($request->all(), $this->rules(false));
        if ($validator->fails()) return $this->validationError($validator);

        $values = $validator->validated();
        if (array_key_exists('snmp_community', $values) && blank($values['snmp_community'])) {
            unset($values['snmp_community']);
        }
        $olt->update($values);

        return response()->json([
            'success' => true,
            'message' => 'OLT SNMP settings updated.',
            'data' => $olt->fresh()->load('router:id,name,connection_status,is_active')->toSafeArray(),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        OltDevice::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'OLT removed from SolarNet monitoring. The OLT itself was not changed.']);
    }

    public function test(string $id): JsonResponse
    {
        $result = $this->snmp->inspect(OltDevice::findOrFail($id));

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function monitoring(string $id): JsonResponse
    {
        $olt = OltDevice::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'olt' => $olt->toSafeArray(),
                'notice' => 'This screen contains read-only standard SNMP information. Vendor MIB support is required before ONU discovery or OLT actions can be offered safely.',
            ],
        ]);
    }

    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'router_id' => [$creating ? 'required' : 'sometimes', 'uuid', Rule::exists('routers', 'id')],
            'host' => [$creating ? 'required' : 'sometimes', 'ip'],
            'snmp_port' => [$creating ? 'required' : 'sometimes', 'integer', 'between:1,65535'],
            'snmp_version' => [$creating ? 'required' : 'sometimes', Rule::in(['2c'])],
            'snmp_community' => [$creating ? 'required' : 'nullable', 'string', 'min:1', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ];
    }

    private function validationError($validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }
}
