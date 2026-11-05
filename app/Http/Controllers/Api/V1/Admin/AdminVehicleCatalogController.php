<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FuelType;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleMakeResource;
use App\Http\Resources\VehicleModelResource;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminVehicleCatalogController extends Controller
{
    public function storeMake(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:vehicle_makes,name'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $make = VehicleMake::create($validated);

        return response()->json([
            'message' => 'Vehicle make created successfully.',
            'make' => new VehicleMakeResource($make->load('models')),
        ], 201);
    }

    public function updateMake(Request $request, VehicleMake $vehicleMake): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('vehicle_makes', 'name')->ignore($vehicleMake->id)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $vehicleMake->update($validated);

        return response()->json([
            'message' => 'Vehicle make updated successfully.',
            'make' => new VehicleMakeResource($vehicleMake->fresh()->load('models')),
        ]);
    }

    public function destroyMake(VehicleMake $vehicleMake): JsonResponse
    {
        $vehicleMake->delete();

        return response()->json([
            'message' => 'Vehicle make deleted successfully.',
        ]);
    }

    public function storeModel(Request $request, VehicleMake $vehicleMake): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'fuel_type' => ['nullable', Rule::enum(FuelType::class)],
            'vehicle_class_id' => ['nullable', 'uuid', 'exists:vehicle_classes,id'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $model = $vehicleMake->models()->create($validated);

        return response()->json([
            'message' => 'Vehicle model created successfully.',
            'model' => new VehicleModelResource($model),
        ], 201);
    }

    public function updateModel(Request $request, VehicleModel $vehicleModel): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'fuel_type' => ['nullable', Rule::enum(FuelType::class)],
            'vehicle_class_id' => ['nullable', 'uuid', 'exists:vehicle_classes,id'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $vehicleModel->update($validated);

        return response()->json([
            'message' => 'Vehicle model updated successfully.',
            'model' => new VehicleModelResource($vehicleModel->fresh()),
        ]);
    }

    public function destroyModel(VehicleModel $vehicleModel): JsonResponse
    {
        $vehicleModel->delete();

        return response()->json([
            'message' => 'Vehicle model deleted successfully.',
        ]);
    }
}
