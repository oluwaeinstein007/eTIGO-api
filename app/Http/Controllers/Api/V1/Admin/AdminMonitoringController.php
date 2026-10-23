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
use App\Services\EtaService;
use App\Services\RideRouteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminMonitoringController extends Controller
{
    public function __construct(
        private DriverLocationService $locationService,
        private EtaService $etaService,
        private RideRouteService $routeService,
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

    /**
     * @param  array{lat: float, lng: float, heading: float, speed: float, timestamp: int}|null  $location
     * @return array{lat: float, lng: float, heading: float, speed: float, timestamp: int, updated_at: string|null}|null
     */
    private function formatLocation(?array $location): ?array
    {
        if ($location === null) {
            return null;
        }

        return $location + [
            'updated_at' => $location['timestamp'] > 0
                ? Carbon::createFromTimestamp($location['timestamp'])->toIso8601String()
                : null,
        ];
    }

    /**
     * @return array{lat: float, lng: float, heading: float, speed: float, timestamp: int}|null
     */
    private function driverLocation(Ride $ride): ?array
    {
        if (! $ride->driver_id) {
            return null;
        }

        $driverModelId = $ride->driver?->driver?->id;

        return ($driverModelId ? $this->locationService->getDriverLocation((string) $driverModelId) : null)
            ?? $this->locationService->getDriverLocation($ride->driver_id);
    }

    /**
     * @param  array<string, float>  $ratings
     * @return array<string, mixed>
     */
    private function monitoringPayload(Ride $ride, array $ratings, ?SosIncident $activeSos): array
    {
        $resource = (new RideResource($ride))->resolve();

        if ($ride->driver_id) {
            $location = $this->driverLocation($ride);
            $resource['driver_location'] = $this->formatLocation($location);
            $resource['driver_rating'] = $ratings[$ride->driver_id] ?? null;
            $resource['eta'] = $this->etaService->getMonitoringEta($ride, $location);
        }

        $transitions = $ride->stateTransitions->sortBy('created_at');
        $resource['stage_timestamps'] = [
            'requested_at' => $ride->created_at?->toIso8601String(),
            'matched_at' => $ride->matched_at?->toIso8601String(),
            'driver_en_route_at' => $transitions->firstWhere('to_state', RideStatus::DriverEnRoute)?->created_at?->toIso8601String(),
            'driver_arrived_at' => $transitions->firstWhere('to_state', RideStatus::DriverArrived)?->created_at?->toIso8601String(),
            'started_at' => $ride->started_at?->toIso8601String(),
        ];

        $resource['has_active_sos'] = $activeSos !== null;
        if ($activeSos) {
            $raisedById = $activeSos->triggered_by_user_id;

            $resource['sos'] = [
                'id' => $activeSos->id,
                'status' => $activeSos->status->value,
                'trigger_type' => $activeSos->trigger_type->value,
                'raised_by' => [
                    'role' => match (true) {
                        $raisedById !== null && $raisedById === $ride->passenger_id => 'passenger',
                        $raisedById !== null && $raisedById === $ride->driver_id => 'driver',
                        default => $activeSos->trigger_type->value,
                    },
                    'user_id' => $raisedById,
                    'name' => $activeSos->triggeredBy
                        ? trim($activeSos->triggeredBy->first_name.' '.$activeSos->triggeredBy->last_name)
                        : null,
                ],
                'created_at' => $activeSos->created_at?->toIso8601String(),
            ];
        }

        return $resource;
    }

    /**
     * A case-insensitive "contains" pattern with `%`, `_` and `\` matched literally.
     */
    private function likeTerm(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
    }

    public function activeRides(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort_by' => ['sometimes', 'string', 'in:requested_at,status'],
            'sort_dir' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);
        $sortDir = $request->input('sort_dir', 'desc');

        $rides = Ride::whereIn('status', RideStatus::activeStatuses())
            ->with(['city', 'vehicleClass', 'passenger', 'driver.driver', 'stateTransitions', 'scheduledRide'])
            ->when($request->input('city_id'), fn ($q, $v) => $q->where('city_id', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), function ($q, $search) {
                $term = $this->likeTerm($search);
                $fullName = fn ($q) => $q->where(DB::raw("concat(first_name, ' ', last_name)"), 'ilike', $term);

                $q->where(function ($q) use ($term, $fullName) {
                    $q->where('reference_number', 'ilike', $term)
                        ->orWhere('pickup_address', 'ilike', $term)
                        ->orWhere('destination_address', 'ilike', $term)
                        ->orWhere('rider_name', 'ilike', $term)
                        ->orWhereHas('passenger', $fullName)
                        ->orWhereHas('driver', $fullName);
                });
            })
            ->when(
                $request->input('sort_by') === 'status',
                function ($q) use ($sortDir) {
                    // Lifecycle order (requested → in_progress), not alphabetical.
                    $statuses = array_map(fn (RideStatus $s) => $s->value, RideStatus::activeStatuses());
                    $cases = implode(' ', array_map(fn (int $i) => "WHEN ? THEN {$i}", array_keys($statuses)));

                    $q->orderByRaw("CASE status {$cases} END {$sortDir}", $statuses)
                        ->orderByDesc('created_at');
                },
                fn ($q) => $q->orderBy('created_at', $sortDir),
            )
            ->orderBy('id', $sortDir)
            ->paginate($perPage);

        $rideIds = $rides->pluck('id')->all();
        $driverUserIds = $rides->pluck('driver_id')->filter()->unique()->values()->all();
        $ratings = $this->bulkAverageRatings($driverUserIds);

        $activeSos = SosIncident::whereIn('ride_id', $rideIds)
            ->active()
            ->with('triggeredBy')
            ->orderBy('created_at')
            ->get()
            ->groupBy('ride_id');

        $ridesData = $rides->through(
            fn (Ride $ride) => $this->monitoringPayload($ride, $ratings, $activeSos->get($ride->id)?->first()),
        );

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

    public function rideDetail(Ride $ride): JsonResponse
    {
        $ride->load(['city', 'vehicleClass', 'passenger', 'driver.driver.vehicle.vehicleClass', 'stateTransitions', 'scheduledRide']);

        $ratings = $this->bulkAverageRatings(array_filter([$ride->driver_id]));

        $activeSos = SosIncident::where('ride_id', $ride->id)
            ->active()
            ->with('triggeredBy')
            ->orderBy('created_at')
            ->first();

        $payload = $this->monitoringPayload($ride, $ratings, $activeSos);

        $vehicle = $ride->driver?->driver?->vehicle;
        $payload['driver_vehicle'] = $vehicle ? [
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'colour' => $vehicle->colour,
            'plate_number' => $vehicle->plate_number,
            'vehicle_class' => $vehicle->vehicleClass?->name,
        ] : null;
        $payload['route'] = $this->routeService->getPlannedRoute($ride);

        return response()->json(['ride' => $payload]);
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
                'location' => $this->formatLocation($location),
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
            ->withinManualAssignmentWindow()
            ->with(['city', 'vehicleClass', 'passenger', 'driver.driver.vehicle', 'scheduledRide'])
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
                $term = $this->likeTerm($search);

                $q->where(function ($q) use ($term) {
                    $q->where('reference_number', 'ilike', $term)
                        ->orWhereHas('passenger', function ($q) use ($term) {
                            $q->where(DB::raw("concat(first_name, ' ', last_name)"), 'ilike', $term);
                        })
                        ->orWhereHas('city', fn ($q) => $q->where('name', 'ilike', $term))
                        ->orWhereHas('vehicleClass', function ($q) use ($term) {
                            $q->where('name', 'ilike', $term)->orWhere('display_name', 'ilike', $term);
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

        $countsByStatus = Ride::whereIn('status', $allowedStatuses)
            ->withinManualAssignmentWindow()
            ->when($request->input('city_id'), fn ($q, $v) => $q->where('city_id', $v))
            ->selectRaw('status, (driver_id IS NULL) as unassigned, COUNT(*) as count')
            ->groupBy('status', 'unassigned')
            ->get();

        return response()->json([
            'rides' => $ridesData->items(),
            'meta' => [
                'current_page' => $rides->currentPage(),
                'last_page' => $rides->lastPage(),
                'per_page' => $rides->perPage(),
                'total' => $rides->total(),
            ],
            'summary' => [
                'awaiting_driver' => (int) $countsByStatus->where('unassigned', true)->sum('count'),
                'by_status' => collect($allowedStatuses)
                    ->mapWithKeys(fn (RideStatus $status) => [
                        $status->value => (int) $countsByStatus->where('status', $status)->sum('count'),
                    ]),
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
            ->dispatchable()
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
                'location' => $this->formatLocation($location),
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
