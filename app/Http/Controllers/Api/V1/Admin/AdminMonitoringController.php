<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Models\Driver;
use App\Models\Ride;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMonitoringController extends Controller
{
    public function __construct(
        private DriverLocationService $locationService,
    ) {}

    public function activeRides(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);

        $rides = Ride::whereIn('status', RideStatus::activeStatuses())
            ->with(['city', 'vehicleClass', 'passenger', 'driver.driver'])
            ->when($request->input('city_id'), fn ($q, $v) => $q->where('city_id', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $ridesData = $rides->through(function (Ride $ride) {
            $resource = (new RideResource($ride))->resolve();

            if ($ride->driver_id) {
                $location = $this->locationService->getDriverLocation($ride->driver_id);
                $resource['driver_location'] = $location;
            }

            return $resource;
        });

        $summaryQuery = Ride::whereIn('status', RideStatus::activeStatuses())
            ->when($request->input('city_id'), fn ($q, $v) => $q->where('city_id', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v));

        return response()->json([
            'rides' => $ridesData->items(),
            'meta' => [
                'current_page' => $rides->currentPage(),
                'last_page' => $rides->lastPage(),
                'per_page' => $rides->perPage(),
                'total' => $rides->total(),
            ],
            'summary' => [
                'total_active' => (clone $summaryQuery)->count(),
                'by_status' => (clone $summaryQuery)
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status'),
            ],
        ]);
    }

    public function onlineDrivers(Request $request): JsonResponse
    {
        $drivers = Driver::where('is_online', true)
            ->where('status', DriverStatus::Approved)
            ->with(['user', 'city', 'vehicle.vehicleClass'])
            ->when($request->input('city_id'), fn ($q, $v) => $q->where('city_id', $v))
            ->get();

        $userIds = $drivers->pluck('user_id')->all();
        $activeRides = Ride::whereIn('driver_id', $userIds)
            ->whereIn('status', [
                RideStatus::Matched,
                RideStatus::DriverEnRoute,
                RideStatus::DriverArrived,
                RideStatus::InProgress,
            ])
            ->get(['id', 'driver_id', 'reference_number', 'status', 'pickup_address', 'destination_address'])
            ->keyBy('driver_id');

        $driversWithLocations = $drivers->map(function (Driver $driver) use ($activeRides) {
            $location = $this->locationService->getDriverLocation($driver->user_id);

            $activeRide = $activeRides->get($driver->user_id);

            return [
                'driver_id' => $driver->id,
                'user_id' => $driver->user_id,
                'name' => $driver->user->first_name . ' ' . $driver->user->last_name,
                'phone' => $driver->user->phone,
                'reference_number' => $driver->reference_number,
                'city' => $driver->city?->name,
                'vehicle' => $driver->vehicle ? [
                    'make' => $driver->vehicle->make,
                    'model' => $driver->vehicle->model,
                    'plate_number' => $driver->vehicle->plate_number,
                    'colour' => $driver->vehicle->colour,
                    'vehicle_class' => $driver->vehicle->vehicleClass?->name,
                ] : null,
                'location' => $location,
                'active_ride' => $activeRide ? [
                    'id' => $activeRide->id,
                    'reference_number' => $activeRide->reference_number,
                    'status' => $activeRide->status->value,
                    'pickup_address' => $activeRide->pickup_address,
                    'destination_address' => $activeRide->destination_address,
                ] : null,
                'is_on_trip' => $activeRide !== null,
            ];
        });

        return response()->json([
            'drivers' => $driversWithLocations,
            'summary' => [
                'total_online' => $drivers->count(),
                'on_trip' => $driversWithLocations->where('is_on_trip', true)->count(),
                'available' => $driversWithLocations->where('is_on_trip', false)->count(),
            ],
        ]);
    }

    public function pendingRides(Request $request): JsonResponse
    {
        $rides = Ride::whereIn('status', [RideStatus::Requested, RideStatus::Searching, RideStatus::NoDriverFound])
            ->whereNull('driver_id')
            ->with(['city', 'vehicleClass', 'passenger'])
            ->when($request->input('city_id'), fn ($q, $v) => $q->where('city_id', $v))
            ->when($request->input('vehicle_class_id'), fn ($q, $v) => $q->where('vehicle_class_id', $v))
            ->orderBy('created_at')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json([
            'rides' => RideResource::collection($rides),
            'meta' => [
                'current_page' => $rides->currentPage(),
                'last_page' => $rides->lastPage(),
                'per_page' => $rides->perPage(),
                'total' => $rides->total(),
            ],
        ]);
    }

    public function availableDrivers(Request $request, Ride $ride): JsonResponse
    {
        $validator = validator($request->only('radius_km'), [
            'radius_km' => ['sometimes', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid radius_km.', 'errors' => $validator->errors()], 422);
        }

        $radiusKm = (float) $request->input('radius_km', 5);
        $limit = min(max((int) $request->input('limit', 20), 1), 50);

        $nearbyFromRedis = $this->locationService->findNearbyDrivers(
            (float) $ride->pickup_lat,
            (float) $ride->pickup_lng,
            $radiusKm,
            500,
        );

        if (empty($nearbyFromRedis)) {
            return response()->json(['drivers' => [], 'meta' => ['total' => 0, 'radius_km' => $radiusKm]]);
        }

        $userIds = array_column($nearbyFromRedis, 'driver_id');
        $distanceMap = [];
        foreach ($nearbyFromRedis as $entry) {
            $distanceMap[$entry['driver_id']] = $entry['distance_km'];
        }

        $drivers = Driver::where('status', DriverStatus::Approved)
            ->where('is_online', true)
            ->where('city_id', $ride->city_id)
            ->whereIn('user_id', $userIds)
            ->whereHas('vehicle', fn ($q) => $q->where('vehicle_class_id', $ride->vehicle_class_id))
            ->whereDoesntHave('user', function ($q) {
                $q->whereHas('driverRides', fn ($r) => $r->whereIn('status', [
                    RideStatus::Matched,
                    RideStatus::DriverEnRoute,
                    RideStatus::DriverArrived,
                    RideStatus::InProgress,
                ]));
            })
            ->with(['user', 'vehicle.vehicleClass'])
            ->get();

        $result = $drivers->map(function (Driver $driver) use ($distanceMap) {
            $location = $this->locationService->getDriverLocation($driver->user_id);

            return [
                'driver_id' => $driver->id,
                'user_id' => $driver->user_id,
                'name' => $driver->user->first_name . ' ' . $driver->user->last_name,
                'phone' => $driver->user->phone,
                'reference_number' => $driver->reference_number,
                'vehicle' => [
                    'make' => $driver->vehicle->make,
                    'model' => $driver->vehicle->model,
                    'plate_number' => $driver->vehicle->plate_number,
                    'colour' => $driver->vehicle->colour,
                    'vehicle_class' => $driver->vehicle->vehicleClass?->name,
                ],
                'location' => $location,
                'distance_km' => round($distanceMap[$driver->user_id] ?? 0, 2),
            ];
        })->sortBy('distance_km')->values()->take($limit);

        return response()->json([
            'drivers' => $result,
            'meta' => [
                'total' => $result->count(),
                'radius_km' => $radiusKm,
                'ride_id' => $ride->id,
                'pickup' => [
                    'lat' => $ride->pickup_lat,
                    'lng' => $ride->pickup_lng,
                    'address' => $ride->pickup_address,
                ],
            ],
        ]);
    }
}
