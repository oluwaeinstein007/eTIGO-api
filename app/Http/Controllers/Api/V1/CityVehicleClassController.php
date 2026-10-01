<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleClassResource;
use App\Models\City;
use App\Services\AppCacheService;
use Illuminate\Http\JsonResponse;

class CityVehicleClassController extends Controller
{
    public function __construct(
        private AppCacheService $cache,
    ) {}

    public function index(City $city): JsonResponse
    {
        abort_unless($city->is_active, 404);

        $vehicleClasses = $this->cache->cityVehicleClasses($city->id);

        return response()->json([
            'vehicle_classes' => VehicleClassResource::collection($vehicleClasses),
        ]);
    }
}
