<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverAvailabilityPlanResource;
use App\Models\DriverAvailabilityPlan;
use App\Services\DriverAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Voluntary availability plans (OQ-20): when and where a driver expects to
 * drive. No penalty for changing or skipping one; going online never
 * depends on it.
 */
class DriverAvailabilityPlanController extends Controller
{
    public function __construct(
        private DriverAvailabilityService $availability,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $plans = DriverAvailabilityPlan::where('driver_id', $driver->id)
            ->upcoming()
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'plans' => DriverAvailabilityPlanResource::collection($plans),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'area_label' => ['nullable', 'string', 'min:2', 'max:60'],
            'repeat_weekly' => ['sometimes', 'integer', 'min:0', 'max:3'],
        ]);

        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        $plans = $this->availability->create($driver, $validated);

        return response()->json([
            'message' => $plans->count() === 1 ? 'Plan saved.' : "{$plans->count()} plans saved.",
            'plans' => DriverAvailabilityPlanResource::collection($plans),
        ], 201);
    }

    public function destroy(Request $request, DriverAvailabilityPlan $plan): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver || $plan->driver_id !== $driver->id) {
            return response()->json(['message' => 'Plan not found.'], 404);
        }

        if ($plan->ends_at->isPast()) {
            return response()->json(['message' => 'This plan has already ended.'], 422);
        }

        $plan->delete();

        return response()->json(['message' => 'Plan removed.']);
    }
}
