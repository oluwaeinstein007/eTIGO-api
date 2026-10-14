<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RideResource;
use App\Models\Driver;
use App\Models\Rating;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminMonitoringController extends Controller
{
    public function __construct(
        private DriverLocationService $locationService,
    ) {}

    private function bulkAverageRatings(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return Rating::whereIn('rated_user_id', $userIds)
            ->groupBy('rated_user_id')
            ->select('rated_user_id', DB::raw('round(avg(score)::numeric, 2) as avg_rating'))
            ->pluck('avg_rating', 'rated_user_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    public function activeRides(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);

        $rides = Ride::whereIn('status', RideStatus::activeStatuses())
            ->with(['city', 'vehicleClass', 'passenger', 'driver.driver', 'stateTransitions'])
            ->when($request->input('city_id'), fn ($q, $v) => $q->where('city_id', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $rideIds = $rides->pluck('id')->all();
        $driverUserIds = $rides->pluck('driver_id')->filter()->unique()->values()->all();
        $ratings = $this->bulkAverageRatings($driverUserIds);

        $activeSos = SosIncident::whereIn('ride_id', $rideIds)
            ->active()
            ->get()
            ->groupBy('ride_id');

        $ridesData = $rides->through(function (Ride $ride) use ($activeSos, $ratings) {
            $resource = (new RideResource($ride))->resolve();

            if ($ride->driver_id) {
                $driverModelId = $ride->driver?->driver?->id;
                $location = ($driverModelId ? $this->locationService->getDriverLocation((string) $driverModelId) : null)
                    ?? $this->locationService->getDriverLocation($ride->driver_id);
                $resource['driver_location'] = $location;
                $resource['driver_rating'] = $ratings[$ride->driver_id] ?? null;
            }

            $transitions = $ride->stateTransitions->sortBy('created_at');
            $resource['stage_timestamps'] = [
                'requested_at' => $ride->created_at?->toIso8601String(),
                'matched_at' => $ride->matched_at?->toIso8601String(),
                'driver_arrived_at' => $transitions->firstWhere('to_state', RideStatus::DriverArrived)?->created_at?->toIso8601String(),
                'started_at' => $ride->started_at?->toIso8601String(),
            ];

            $rideSos = $activeSos->get($ride->id);
            $resource['has_active_sos'] = $rideSos !== null && $rideSos->isNotEmpty();
            if ($resource['has_active_sos']) {
                $incident = $rideSos->first();
                $resource['sos'] = [
                    'id' => $incident->id,
                    'status' => $incident->status->value,
                    'trigger_type' => $incident->trigger_type->value,
                    'created_at' => $incident->created_at?->toIso8601String(),
                ];
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
        $ratings = $this->bulkAverageRatings($userIds);

        $activeRides = Ride::whereIn('driver_id', $userIds)
            ->whereIn('status', [
                RideStatus::Matched,
                RideStatus::DriverEnRoute,
                RideStatus::DriverArrived,
                RideStatus::InProgress,
            ])
            ->get(['id', 'driver_id', 'reference_number', 'status', 'pickup_address', 'destination_address'])
            ->keyBy('driver_id');

        $driversWithLocations = $drivers->map(function (Driver $driver) use ($activeRides, $ratings) {
            $location = $this->locationService->getDriverLocation((string) $driver->id)
                ?? $this->locationService->getDriverLocation($driver->user_id);

            $activeRide = $activeRides->get($driver->user_id);

            return [
                'driver_id' => $driver->id,
                'user_id' => $driver->user_id,
                'name' => $driver->user->first_name.' '.$driver->user->last_name,
                'phone' => $driver->user->phone,
                'reference_number' => $driver->reference_number,
                'rating' => $ratings[$driver->user_id] ?? null,
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
        $allowedStatuses = [
            RideStatus::Requested,
            RideStatus::Searching,
            RideStatus::NoDriverFound,
            RideStatus::Matched,
            RideStatus::DriverEnRoute,
        ];

        $rides = Ride::whereIn('status', $allowedStatuses)
            ->with(['city', 'vehicleClass', 'passenger', 'driver.driver.vehicle'])
            ->when($request->input('status'), function ($q, $v) use ($allowedStatuses) {
                $statuses = array_filter(
                    array_map(fn ($s) => RideStatus::tryFrom($s), explode(',', $v)),
                    fn ($s) => $s !== null && in_array($s, $allowedStatuses),
                );
                if ($statuses) {
                    $q->whereIn('status', $statuses);
                }
            }, fn ($q) => $q->whereNull('driver_id'))
            ->when($request->input('city_id'), fn ($q, $v) => $q->where('city_id', $v))
            ->when($request->input('vehicle_class_id'), fn ($q, $v) => $q->where('vehicle_class_id', $v))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('reference_number', 'ilike', "%{$search}%")
                        ->orWhereHas('passenger', function ($q) use ($search) {
                            $q->where(DB::raw("concat(first_name, ' ', last_name)"), 'ilike', "%{$search}%");
                        });
                });
            })
            ->orderBy('created_at')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        $driverUserIds = $rides->pluck('driver_id')->filter()->unique()->values()->all();
        $ratings = $this->bulkAverageRatings($driverUserIds);

        $ridesData = $rides->through(function (Ride $ride) use ($ratings) {
            $resource = (new RideResource($ride))->resolve();

            if ($ride->driver_id) {
                $resource['driver_rating'] = $ratings[$ride->driver_id] ?? null;
                $resource['matched_at'] = $ride->matched_at?->toIso8601String();

                $driverProfile = $ride->driver?->driver;
                if ($driverProfile?->vehicle) {
                    $resource['driver_vehicle'] = [
                        'make' => $driverProfile->vehicle->make,
                        'model' => $driverProfile->vehicle->model,
                        'colour' => $driverProfile->vehicle->colour,
                        'plate_number' => $driverProfile->vehicle->plate_number,
                    ];
                }
            }

            return $resource;
        });

        return response()->json([
            'rides' => $ridesData->items(),
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

        $driverIds = array_column($nearbyFromRedis, 'driver_id');
        $distanceMap = [];
        foreach ($nearbyFromRedis as $entry) {
            $distanceMap[$entry['driver_id']] = $entry['distance_km'];
        }

        $drivers = Driver::where('status', DriverStatus::Approved)
            ->where('is_online', true)
            ->where('city_id', $ride->city_id)
            ->whereIn('id', $driverIds)
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

        $availableUserIds = $drivers->pluck('user_id')->all();
        $ratings = $this->bulkAverageRatings($availableUserIds);

        $result = $drivers->map(function (Driver $driver) use ($distanceMap, $ratings) {
            $location = $this->locationService->getDriverLocation((string) $driver->id)
                ?? $this->locationService->getDriverLocation($driver->user_id);

            $distanceKm = round($distanceMap[$driver->id] ?? 0, 2);
            $avgSpeedKmh = 30;
            $etaMinutes = $distanceKm > 0 ? round(($distanceKm / $avgSpeedKmh) * 60, 1) : 0;

            return [
                'driver_id' => $driver->id,
                'user_id' => $driver->user_id,
                'name' => $driver->user->first_name.' '.$driver->user->last_name,
                'phone' => $driver->user->phone,
                'reference_number' => $driver->reference_number,
                'rating' => $ratings[$driver->user_id] ?? null,
                'vehicle' => [
                    'make' => $driver->vehicle->make,
                    'model' => $driver->vehicle->model,
                    'plate_number' => $driver->vehicle->plate_number,
                    'colour' => $driver->vehicle->colour,
                    'vehicle_class' => $driver->vehicle->vehicleClass?->name,
                ],
                'location' => $location,
                'distance_km' => $distanceKm,
                'eta_minutes' => $etaMinutes,
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
