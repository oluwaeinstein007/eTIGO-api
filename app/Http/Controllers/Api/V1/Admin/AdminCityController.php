<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\City\StoreCityRequest;
use App\Http\Requests\Admin\City\UpdateCityRequest;
use App\Http\Resources\CityResource;
use App\Models\AuditLog;
use App\Models\City;
use App\Services\AppCacheService;
use App\Support\NigerianStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminCityController extends Controller
{
    public function __construct(
        private AppCacheService $cache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = City::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
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

        $cities = $query->latest()->paginate(min($request->integer('per_page', 20), 100));

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
            if (isset($validated['boundary']) && ($validated['boundary']['type'] ?? '') === 'Polygon') {
                $areaSqKm = City::calculateAreaFromPolygon($validated['boundary']['coordinates']);
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
        ], 201);
    }

    public function show(City $city): JsonResponse
    {
        return response()->json([
            'city' => new CityResource($city->load('vehicleClasses')),
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

            if (isset($validated['boundary']) && ($validated['boundary']['type'] ?? '') === 'Polygon') {
                $validated['area_sq_km'] = City::calculateAreaFromPolygon($validated['boundary']['coordinates']);
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
        ]);
    }

    public function toggleStatus(Request $request, City $city): JsonResponse
    {
        $admin = $request->user();
        $oldStatus = $city->is_active;

        DB::transaction(function () use ($city, $admin, $oldStatus) {
            $city->update(['is_active' => ! $city->is_active]);

            AuditLog::record(
                $city,
                $city->is_active ? 'city_activated' : 'city_deactivated',
                $admin,
                ['is_active' => $oldStatus],
                ['is_active' => $city->is_active],
            );
        });

        $this->cache->invalidateCities();

        return response()->json([
            'message' => $city->is_active ? 'City activated successfully.' : 'City deactivated successfully.',
            'city' => new CityResource($city),
        ]);
    }
}
