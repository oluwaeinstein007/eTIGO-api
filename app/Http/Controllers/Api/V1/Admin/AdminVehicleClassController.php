<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VehicleClass\StoreVehicleClassRequest;
use App\Http\Requests\Admin\VehicleClass\UpdateVehicleClassRequest;
use App\Http\Resources\VehicleClassResource;
use App\Models\AuditLog;
use App\Models\VehicleClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminVehicleClassController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = VehicleClass::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $vehicleClasses = $query->orderBy('name')->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'vehicle_classes' => VehicleClassResource::collection($vehicleClasses),
            'meta' => [
                'current_page' => $vehicleClasses->currentPage(),
                'last_page' => $vehicleClasses->lastPage(),
                'per_page' => $vehicleClasses->perPage(),
                'total' => $vehicleClasses->total(),
            ],
        ]);
    }

    public function store(StoreVehicleClassRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $admin = $request->user();

        $cityIds = $validated['city_ids'] ?? [];
        unset($validated['city_ids']);

        $vehicleClass = DB::transaction(function () use ($validated, $admin, $cityIds) {
            $vehicleClass = VehicleClass::create($validated);

            if (! empty($cityIds)) {
                $syncData = array_fill_keys($cityIds, ['is_active' => true, 'sort_order' => 0]);
                $vehicleClass->cities()->attach($syncData);
            }

            AuditLog::record($vehicleClass, 'vehicle_class_created', $admin);

            return $vehicleClass;
        });

        return response()->json([
            'message' => 'Vehicle class created successfully.',
            'vehicle_class' => new VehicleClassResource($vehicleClass),
        ], 201);
    }

    public function show(VehicleClass $vehicleClass): JsonResponse
    {
        return response()->json([
            'vehicle_class' => new VehicleClassResource($vehicleClass),
        ]);
    }

    public function update(UpdateVehicleClassRequest $request, VehicleClass $vehicleClass): JsonResponse
    {
        $validated = $request->validated();
        $admin = $request->user();
        $oldValues = $vehicleClass->only(array_keys($validated));

        DB::transaction(function () use ($vehicleClass, $validated, $admin, $oldValues) {
            $vehicleClass->update($validated);

            AuditLog::record($vehicleClass, 'vehicle_class_updated', $admin, $oldValues, $vehicleClass->only(array_keys($oldValues)));
        });

        return response()->json([
            'message' => 'Vehicle class updated successfully.',
            'vehicle_class' => new VehicleClassResource($vehicleClass->fresh()),
        ]);
    }
}
