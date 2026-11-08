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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminVehicleCatalogController extends Controller
{
    /**
     * Every make, active or not, with every model, for catalog management.
     * The public catalog (GET /vehicle-makes) only ever shows active ones.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $makes = VehicleMake::query()
            ->with('models')
            ->when(array_key_exists('is_active', $validated) && $validated['is_active'] !== null, fn ($q) => $q->where('is_active', $validated['is_active']))
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where('name', 'ilike', "%{$search}%"))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'makes' => VehicleMakeResource::collection($makes),
        ]);
    }

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

        // A rename must free up its old slug (and claim a unique new one) or a
        // later make reusing that name can never be created.
        if (isset($validated['name']) && $validated['name'] !== $vehicleMake->name) {
            $validated['slug'] = $this->uniqueMakeSlug($validated['name'], $vehicleMake->id);
        }

        $vehicleMake->update($validated);

        return response()->json([
            'message' => 'Vehicle make updated successfully.',
            'make' => new VehicleMakeResource($vehicleMake->fresh()->load('models')),
        ]);
    }

    /**
     * A slug for $name that is not already taken by another make, appending
     * `-2`, `-3`, ... on a collision.
     */
    private function uniqueMakeSlug(string $name, string $ignoreId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (VehicleMake::where('slug', $slug)->whereKeyNot($ignoreId)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
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
