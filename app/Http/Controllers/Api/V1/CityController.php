<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Models\City;
use Illuminate\Http\JsonResponse;

class CityController extends Controller
{
    public function index(): JsonResponse
    {
        $cities = City::where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'cities' => CityResource::collection($cities),
        ]);
    }
}
