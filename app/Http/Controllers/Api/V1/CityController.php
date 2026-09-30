<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Services\AppCacheService;
use App\Services\CityDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function __construct(
        private AppCacheService $cache,
        private CityDetectionService $cityDetection,
    ) {}

    public function index(): JsonResponse
    {
        $cities = $this->cache->activeCities();

        return response()->json([
            'cities' => CityResource::collection($cities),
        ]);
    }

    public function detect(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $result = $this->cityDetection->detectCity(
            (float) $request->input('lat'),
            (float) $request->input('lng'),
        );

        if (! $result['city']) {
            return response()->json([
                'message' => 'No active city found for this location.',
                'resolved_address' => $result['resolved_address'],
            ], 404);
        }

        return response()->json([
            'city' => new CityResource($result['city']),
            'resolved_address' => $result['resolved_address'],
        ]);
    }
}
