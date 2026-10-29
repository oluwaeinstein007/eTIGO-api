<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CityLaunchStatus;
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

    /**
     * Live cities. With include_onboarding=1 (the driver sign-up picker),
     * also cities that are recruiting drivers before launch.
     */
    public function index(Request $request): JsonResponse
    {
        $cities = $request->boolean('include_onboarding')
            ? $this->cache->citiesOpenToDrivers()
            : $this->cache->activeCities();

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
            CityLaunchStatus::cases(),
        );

        $city = $result['city'];

        if (! $city) {
            return response()->json([
                'message' => 'No active city found for this location.',
                'resolved_address' => $result['resolved_address'],
            ], 404);
        }

        // In a city that is not open (yet): the same 404 as before, plus the
        // city so the app can offer the waitlist.
        if (! $city->isLive()) {
            return response()->json([
                'message' => $city->unavailableMessage(),
                'resolved_address' => $result['resolved_address'],
                'unavailable_city' => [
                    'id' => $city->id,
                    'name' => $city->name,
                    'launch_status' => $city->launch_status->value,
                    'waitlist_open' => $city->launch_status->acceptsWaitlist(),
                ],
            ], 404);
        }

        return response()->json([
            'city' => new CityResource($result['city']),
            'resolved_address' => $result['resolved_address'],
        ]);
    }
}
