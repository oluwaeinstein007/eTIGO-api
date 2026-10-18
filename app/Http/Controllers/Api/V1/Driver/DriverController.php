<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\CityLaunchStatus;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\AuditLog;
use App\Models\Ride;
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

        if ($driver->is_online) {
            $hasActiveRide = Ride::where('driver_id', $user->id)
                ->whereIn('status', [
                    RideStatus::Matched,
                    RideStatus::DriverEnRoute,
                    RideStatus::DriverArrived,
                    RideStatus::InProgress,
                ])
                ->exists();

            if ($hasActiveRide) {
                return response()->json([
                    'message' => 'You cannot go offline during an active ride. Complete or cancel the ride first.',
                ], 409);
            }
        } else {
            $hasExcessiveNegativeBalance = $this->earningsService->hasExcessiveNegativeBalance($driver->id);
            $cityNotLaunched = $driver->city !== null && ! $driver->city->launch_status->allowsDriversOnline();

            if (! $driver->canGoOnline() || $hasExcessiveNegativeBalance || $cityNotLaunched) {
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

                if ($hasExcessiveNegativeBalance) {
                    $reasons[] = 'Outstanding negative balance exceeds the allowed threshold.';
                }

                if ($cityNotLaunched) {
                    $reasons[] = "E-tiGo has not launched in {$driver->city->name} yet. You can go online from launch day.";
                }

                return response()->json([
                    'message' => 'Cannot go online.',
                    'reasons' => $reasons,
                ], 422);
            }
        }

        $driver->update(['is_online' => ! $driver->is_online]);

        if (! $driver->is_online) {
            try {
                $this->locationService->removeDriver($driver->id, $user->id);
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

        $cityPaused = $driver->is_online && $driver->city?->launch_status === CityLaunchStatus::Paused;
        $bookingsHonoured = $driver->city?->pause_scheduled_rides !== 'cancel';

        return response()->json([
            'message' => $driver->is_online ? 'You are now online.' : 'You are now offline.',
            'driver' => new DriverResource($driver),
            // Paused city: only trips already booked come through.
            'city_notice' => match (true) {
                ! $cityPaused => null,
                $bookingsHonoured => "Rides in {$driver->city->name} are paused. You will only get rides passengers booked before the pause.",
                default => "Rides in {$driver->city->name} are paused and upcoming bookings were cancelled. You will not get new trips until rides resume.",
            },
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
