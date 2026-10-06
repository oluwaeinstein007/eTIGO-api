<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
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

            if (! $driver->vehicle) {
                $reasons[] = 'No vehicle registered.';
            } elseif (! $driver->vehicle->vehicle_class_id) {
                $reasons[] = 'Vehicle class not assigned.';
            }

            return response()->json([
                'message' => 'Cannot go online.',
                'reasons' => $reasons,
            ], 422);
        }

        $driver->update(['is_online' => ! $driver->is_online]);

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
}
