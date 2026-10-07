<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pricing\StorePricingRequest;
use App\Http\Resources\PricingResource;
use App\Models\AuditLog;
use App\Models\PricingConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPricingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PricingConfig::with(['city', 'vehicleClass', 'createdByAdmin']);

        if ($request->filled('city_id')) {
            $query->where('city_id', $request->input('city_id'));
        }

        if ($request->filled('vehicle_class_id')) {
            $query->where('vehicle_class_id', $request->input('vehicle_class_id'));
        }

        if ($request->boolean('current_only')) {
            $query->where('effective_from', '<=', now());
        }

        $configs = $query->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'pricing_configs' => PricingResource::collection($configs),
            'meta' => [
                'current_page' => $configs->currentPage(),
                'last_page' => $configs->lastPage(),
                'per_page' => $configs->perPage(),
                'total' => $configs->total(),
            ],
        ]);
    }

    public function store(StorePricingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $admin = $request->user();

        $config = DB::transaction(function () use ($validated, $admin) {
            $latestVersion = PricingConfig::where('city_id', $validated['city_id'])
                ->where('vehicle_class_id', $validated['vehicle_class_id'])
                ->max('version') ?? 0;

            $config = PricingConfig::create([
                ...$validated,
                'version' => $latestVersion + 1,
                'created_by_admin_id' => $admin->id,
                'created_at' => now(),
            ]);

            AuditLog::record($config, 'pricing_config_created', $admin, null, [
                'city_id' => $config->city_id,
                'vehicle_class_id' => $config->vehicle_class_id,
                'version' => $config->version,
                'base_fare' => $config->base_fare,
                'per_km_rate' => $config->per_km_rate,
                'per_minute_rate' => $config->per_minute_rate,
                'minimum_fare' => $config->minimum_fare,
                'waiting_time_rate' => $config->waiting_time_rate,
                'free_waiting_minutes' => $config->free_waiting_minutes,
                'effective_from' => $config->effective_from->toIso8601String(),
            ]);

            return $config;
        });

        return response()->json([
            'message' => 'Pricing configuration created successfully.',
            'pricing_config' => new PricingResource($config->load(['city', 'vehicleClass'])),
        ], 201);
    }

    public function show(PricingConfig $pricingConfig): JsonResponse
    {
        return response()->json([
            'pricing_config' => new PricingResource(
                $pricingConfig->load(['city', 'vehicleClass', 'createdByAdmin']),
            ),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $request->validate([
            'city_id' => ['required', 'uuid', 'exists:cities,id'],
            'vehicle_class_id' => ['required', 'uuid', 'exists:vehicle_classes,id'],
        ]);

        $config = PricingConfig::currentFor(
            $request->input('city_id'),
            $request->input('vehicle_class_id'),
        );

        if (! $config) {
            return response()->json([
                'message' => 'No active pricing configuration found for this city and vehicle class.',
            ], 404);
        }

        return response()->json([
            'pricing_config' => new PricingResource($config->load(['city', 'vehicleClass'])),
        ]);
    }
}
