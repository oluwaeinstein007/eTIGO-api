<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CityLaunchStatus;
use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Exceptions\CityLaunchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\City\StoreCityRequest;
use App\Http\Requests\Admin\City\UpdateCityRequest;
use App\Http\Resources\CityResource;
use App\Models\AuditLog;
use App\Models\City;
use App\Services\AppCacheService;
use App\Services\CityBoundaryService;
use App\Services\CityLaunchService;
use App\Services\CityReadinessService;
use App\Services\PricingCoverageService;
use App\Support\NigerianStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminCityController extends Controller
{
    public function __construct(
        private AppCacheService $cache,
        private PricingCoverageService $coverage,
        private CityLaunchService $launch,
        private CityReadinessService $readiness,
        private CityBoundaryService $boundaries,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = City::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('launch_status')) {
            $statuses = array_filter(array_map(
                fn (string $status) => CityLaunchStatus::tryFrom(trim($status)),
                explode(',', (string) $request->input('launch_status')),
            ));
            $query->whereIn('launch_status', $statuses);
        }

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->input('search').'%');
        }

        if ($request->filled('state')) {
            $query->where('state', $request->input('state'));
        }

        if ($request->filled('region')) {
            $query->where('region', $request->input('region'));
        }

        $query->withCount([
            'vehicleClasses',
            'drivers',
            'waitlistEntries' => fn ($q) => $q->whereNull('notified_at'),
        ]);

        $cities = $query->latest()->paginate($this->perPage($request));

        $gapCounts = $this->coverage->gaps($cities->getCollection()->modelKeys())->countBy('city_id');
        $cities->getCollection()->each(fn (City $city) => $city->unpriced_vehicle_classes_count = $gapCounts->get($city->id, 0));

        $overlaps = $this->boundaries->overlapsFor($cities->getCollection());
        $cities->getCollection()->each(fn (City $city) => $city->overlapping_cities = array_map(
            fn (City $other) => ['id' => $other->id, 'name' => $other->name],
            $overlaps[$city->id],
        ));

        return response()->json([
            'cities' => CityResource::collection($cities),
            'meta' => [
                'current_page' => $cities->currentPage(),
                'last_page' => $cities->lastPage(),
                'per_page' => $cities->perPage(),
                'total' => $cities->total(),
            ],
        ]);
    }

    public function store(StoreCityRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $admin = $request->user();

        $vehicleClassIds = $validated['vehicle_class_ids'] ?? [];
        unset($validated['vehicle_class_ids']);

        $validated['region'] = NigerianStates::regionFor($validated['state']);
        $validated['timezone'] ??= 'Africa/Lagos';
        $validated['currency_code'] ??= 'NGN';

        $city = DB::transaction(function () use ($validated, $admin, $vehicleClassIds) {
            $slug = Str::slug($validated['name']);
            if (City::where('slug', $slug)->exists()) {
                $slug .= '-'.City::where('slug', 'like', $slug.'%')->count();
            }

            $areaSqKm = null;
            if (isset($validated['boundary'])) {
                $boundaryType = $validated['boundary']['type'] ?? '';
                if ($boundaryType === 'Polygon') {
                    $areaSqKm = City::calculateAreaFromPolygon($validated['boundary']['coordinates']);
                } elseif ($boundaryType === 'Point') {
                    $areaSqKm = City::calculateAreaFromPoint($validated['boundary']['radius_km'] ?? 30);
                }
            }

            $city = City::create([
                ...$validated,
                'slug' => $slug,
                'area_sq_km' => $areaSqKm,
            ]);

            if (! empty($vehicleClassIds)) {
                $syncData = [];
                foreach ($vehicleClassIds as $i => $vcId) {
                    $syncData[$vcId] = ['is_active' => true, 'sort_order' => $i + 1];
                }
                $city->vehicleClasses()->sync($syncData);
            }

            AuditLog::record($city, 'city_created', $admin);

            return $city;
        });

        $this->cache->invalidateCities();

        return response()->json([
            'message' => 'City created successfully.',
            'city' => new CityResource($city->load('vehicleClasses')),
            'warnings' => [...$this->coverage->warningsForCity($city), ...$this->boundaries->warningsForCity($city)],
        ], 201);
    }

    public function show(City $city): JsonResponse
    {
        $city->load('vehicleClasses')
            ->loadCount([
                'vehicleClasses',
                'drivers',
                'rides as trips_count',
                'rides as active_rides_count' => fn ($q) => $q->whereIn('status', [
                    RideStatus::Matched,
                    RideStatus::DriverEnRoute,
                    RideStatus::DriverArrived,
                    RideStatus::InProgress,
                ]),
                'drivers as active_drivers_count' => fn ($q) => $q
                    ->where('is_online', true)
                    ->where('status', DriverStatus::Approved),
                'waitlistEntries' => fn ($q) => $q->whereNull('notified_at'),
            ]);

        $readiness = $this->readiness->report($city);

        return response()->json([
            'city' => new CityResource($city),
            'readiness' => [
                'ready' => $readiness['ready'],
                'missing' => $readiness['missing'],
                'counts' => $readiness['counts'],
            ],
            'warnings' => [...$this->coverage->warningsForCity($city), ...$this->boundaries->warningsForCity($city)],
        ]);
    }

    public function update(UpdateCityRequest $request, City $city): JsonResponse
    {
        $validated = $request->validated();
        $admin = $request->user();

        $vehicleClassIds = $validated['vehicle_class_ids'] ?? null;
        unset($validated['vehicle_class_ids']);

        $oldValues = $city->only(array_keys($validated));

        DB::transaction(function () use ($city, $validated, $admin, $oldValues, $vehicleClassIds) {
            if (isset($validated['name'])) {
                $slug = Str::slug($validated['name']);
                if (City::where('slug', $slug)->where('id', '!=', $city->id)->exists()) {
                    $slug .= '-'.City::where('slug', 'like', $slug.'%')->count();
                }
                $validated['slug'] = $slug;
            }

            if (isset($validated['state'])) {
                $validated['region'] = NigerianStates::regionFor($validated['state']);
            }

            if (isset($validated['boundary'])) {
                $boundaryType = $validated['boundary']['type'] ?? '';
                if ($boundaryType === 'Polygon') {
                    $validated['area_sq_km'] = City::calculateAreaFromPolygon($validated['boundary']['coordinates']);
                } elseif ($boundaryType === 'Point') {
                    $validated['area_sq_km'] = City::calculateAreaFromPoint($validated['boundary']['radius_km'] ?? 30);
                } else {
                    $validated['area_sq_km'] = null;
                }
            }

            $city->update($validated);

            if ($vehicleClassIds !== null) {
                $syncData = [];
                foreach ($vehicleClassIds as $i => $vcId) {
                    $syncData[$vcId] = ['is_active' => true, 'sort_order' => $i + 1];
                }
                $city->vehicleClasses()->sync($syncData);
            }

            AuditLog::record($city, 'city_updated', $admin, $oldValues, $city->only(array_keys($oldValues)));
        });

        $this->cache->invalidateCities();

        return response()->json([
            'message' => 'City updated successfully.',
            'city' => new CityResource($city->fresh()->load('vehicleClasses')),
            'warnings' => [...$this->coverage->warningsForCity($city), ...$this->boundaries->warningsForCity($city)],
        ]);
    }

    /**
     * The old on/off switch, kept for existing dashboards: a live city is
     * paused (upcoming bookings follow the pause setting); an onboarding
     * city launches (readiness checklist) and a paused one resumes. New
     * clients use POST /admin/cities/{city}/launch-status.
     */
    public function toggleStatus(Request $request, City $city): JsonResponse
    {
        $to = $city->isLive() ? CityLaunchStatus::Paused : CityLaunchStatus::Live;

        try {
            $result = $this->launch->changeStatus($city, $to, $request->user());
        } catch (CityLaunchException $e) {
            return response()->json(array_filter([
                'message' => $e->getMessage(),
                'readiness' => $e->readiness,
            ]), 422);
        }

        $city = $result['city'];

        return response()->json([
            'message' => $city->isLive() ? 'City activated successfully.' : 'City deactivated successfully.',
            'city' => new CityResource($city),
            'scheduled_rides' => $result['scheduled_rides'],
            'scheduled_rides_to_cancel' => $result['scheduled_rides_to_cancel'],
            'active_rides' => $result['active_rides'],
            'warnings' => [...$this->coverage->warningsForCity($city), ...$this->boundaries->warningsForCity($city)],
        ]);
    }
}
