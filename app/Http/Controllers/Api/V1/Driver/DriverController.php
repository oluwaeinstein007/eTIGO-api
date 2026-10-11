<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\AuditLog;
use App\Services\DriverEarningsService;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DriverController extends Controller
{
    public function __construct(
        private DriverLocationService $locationService,
        private DriverEarningsService $earningsService,
    ) {}

    public function toggleOnline(Request $request): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver) {
            return response()->json([
                'message' => 'Driver profile not found.',
            ], 404);
        }

        if (! $driver->canGoOnline() && ! $driver->is_online) {
            $reasons = [];

            if (! $driver->isApproved()) {
                $reasons[] = 'Driver account is not approved.';
            }

            if ($driver->isSuspended()) {
                $reasons[] = 'Driver account is suspended.';
            }

            if (! $driver->isKycVerified()) {
                $reasons[] = 'KYC verification is not complete.';
            }

            if (! $driver->vehicle) {
                $reasons[] = 'No vehicle registered.';
            } elseif (! $driver->vehicle->vehicle_class_id) {
                $reasons[] = 'Vehicle class not assigned.';
            }

            if ($this->earningsService->hasExcessiveNegativeBalance($user->id)) {
                $reasons[] = 'Outstanding negative balance exceeds the allowed threshold.';
            }

            return response()->json([
                'message' => 'Cannot go online.',
                'reasons' => $reasons,
            ], 422);
        }

        $driver->update(['is_online' => ! $driver->is_online]);

        if (! $driver->is_online) {
            try {
                $this->locationService->removeDriver($driver->id);
            } catch (\Throwable $e) {
                Log::warning('Failed to remove driver location from cache', [
                    'driver_id' => $driver->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        AuditLog::record(
            $driver,
            $driver->is_online ? 'driver_went_online' : 'driver_went_offline',
            $user,
        );

        $driver->load(['user', 'vehicle.vehicleClass', 'city']);

        return response()->json([
            'message' => $driver->is_online ? 'You are now online.' : 'You are now offline.',
            'driver' => new DriverResource($driver),
        ]);
    }

    public function location(Request $request): JsonResponse
    {
        $driver = $request->user()->driver;

        if (! $driver) {
            return response()->json(['message' => 'Driver profile not found.'], 404);
        }

        if (! $driver->is_online) {
            return response()->json(['message' => 'Driver is offline.'], 409);
        }

        $location = $this->locationService->getDriverLocation((string) $driver->id);

        if (! $location || $location['timestamp'] < now()->subSeconds(300)->timestamp) {
            return response()->json(['message' => 'No recent driver location is available.'], 404);
        }

        $driver->loadMissing(['city', 'vehicle.vehicleClass']);

        return response()->json([
            'location' => $location,
            'city_id' => $driver->city_id,
            'vehicle_class_id' => $driver->vehicle?->vehicle_class_id,
            'is_online' => $driver->is_online,
        ]);
    }
}
