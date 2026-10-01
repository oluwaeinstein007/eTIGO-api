<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\City\UpdateCityVehicleClassesRequest;
use App\Http\Resources\CityResource;
use App\Models\AuditLog;
use App\Models\City;
use App\Services\AppCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminCityVehicleClassController extends Controller
{
    public function __construct(
        private AppCacheService $cache,
    ) {}

    public function update(UpdateCityVehicleClassesRequest $request, City $city): JsonResponse
    {
        $validated = $request->validated();
        $admin = $request->user();

        DB::transaction(function () use ($city, $validated, $admin) {
            $syncData = [];
            foreach ($validated['vehicle_classes'] as $entry) {
                $syncData[$entry['vehicle_class_id']] = [
                    'is_active' => $entry['is_active'],
                    'sort_order' => $entry['sort_order'] ?? 0,
                ];
            }

            $city->vehicleClasses()->sync($syncData);

            AuditLog::record($city, 'city_vehicle_classes_updated', $admin, null, ['vehicle_classes' => $validated['vehicle_classes']]);
        });

        $this->cache->invalidateCities();

        return response()->json([
            'message' => 'City vehicle classes updated successfully.',
            'city' => new CityResource($city->fresh()->load('vehicleClasses')),
        ]);
    }
}
