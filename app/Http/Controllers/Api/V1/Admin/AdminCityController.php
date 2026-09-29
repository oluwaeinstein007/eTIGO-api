<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\City\StoreCityRequest;
use App\Http\Requests\Admin\City\UpdateCityRequest;
use App\Http\Resources\CityResource;
use App\Models\AuditLog;
use App\Models\City;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminCityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = City::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->input('search').'%');
        }

        $cities = $query->latest()->paginate($request->integer('per_page', 20));

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

        $city = DB::transaction(function () use ($validated, $admin) {
            $city = City::create([
                ...$validated,
                'slug' => Str::slug($validated['name']),
            ]);

            AuditLog::record($city, 'city_created', $admin);

            return $city;
        });

        return response()->json([
            'message' => 'City created successfully.',
            'city' => new CityResource($city),
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
        $oldValues = $city->only(array_keys($validated));

        DB::transaction(function () use ($city, $validated, $admin, $oldValues) {
            if (isset($validated['name'])) {
                $validated['slug'] = Str::slug($validated['name']);
            }

            $city->update($validated);

            AuditLog::record($city, 'city_updated', $admin, $oldValues, $city->only(array_keys($oldValues)));
        });

        return response()->json([
            'message' => 'City updated successfully.',
            'city' => new CityResource($city->fresh()),
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

        return response()->json([
            'message' => $city->is_active ? 'City activated successfully.' : 'City deactivated successfully.',
            'city' => new CityResource($city),
        ]);
    }
}
