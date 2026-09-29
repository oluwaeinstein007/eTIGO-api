<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleClassResource;
use App\Models\City;
use Illuminate\Http\JsonResponse;

class CityVehicleClassController extends Controller
{
    public function index(City $city): JsonResponse
    {
        $vehicleClasses = $city->vehicleClasses()
            ->where('vehicle_classes.is_active', true)
            ->wherePivot('is_active', true)
            ->orderBy('vehicle_classes.name')
            ->get();

        return response()->json([
            'vehicle_classes' => VehicleClassResource::collection($vehicleClasses),
        ]);
    }
}
