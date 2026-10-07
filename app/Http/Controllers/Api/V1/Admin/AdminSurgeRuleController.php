<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Surge\StoreSurgeRuleRequest;
use App\Http\Requests\Admin\Surge\UpdateSurgeRuleRequest;
use App\Http\Resources\SurgeRuleResource;
use App\Models\AuditLog;
use App\Models\SurgeRule;
use App\Services\SurgePricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSurgeRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SurgeRule::with(['city', 'vehicleClass', 'createdByAdmin']);

        if ($request->filled('city_id')) {
            $query->where('city_id', $request->input('city_id'));
        }

        if ($request->filled('vehicle_class_id')) {
            $query->where('vehicle_class_id', $request->input('vehicle_class_id'));
        }

        if ($request->boolean('active_only')) {
            $query->active();
        }

        $rules = $query->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'surge_rules' => SurgeRuleResource::collection($rules),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'last_page' => $rules->lastPage(),
                'per_page' => $rules->perPage(),
                'total' => $rules->total(),
            ],
        ]);
    }

    public function store(StoreSurgeRuleRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $admin = $request->user();

        $rule = SurgeRule::create([
            ...$validated,
            'created_by_admin_id' => $admin->id,
        ]);

        $rule->refresh();

        AuditLog::record($rule, 'surge_rule_created', $admin, null, [
            'name' => $rule->name,
            'type' => $rule->type->value,
            'multiplier' => $rule->multiplier,
            'city_id' => $rule->city_id,
            'vehicle_class_id' => $rule->vehicle_class_id,
        ]);

        return response()->json([
            'message' => 'Surge rule created successfully.',
            'surge_rule' => new SurgeRuleResource($rule->load(['city', 'vehicleClass'])),
        ], 201);
    }

    public function show(SurgeRule $surgeRule): JsonResponse
    {
        return response()->json([
            'surge_rule' => new SurgeRuleResource(
                $surgeRule->load(['city', 'vehicleClass', 'createdByAdmin']),
            ),
        ]);
    }

    public function update(UpdateSurgeRuleRequest $request, SurgeRule $surgeRule): JsonResponse
    {
        $validated = $request->validated();
        $admin = $request->user();

        $oldValues = $surgeRule->only(['name', 'type', 'multiplier', 'conditions', 'priority', 'is_active']);

        $surgeRule->update($validated);

        AuditLog::record($surgeRule, 'surge_rule_updated', $admin, $oldValues, $surgeRule->only(['name', 'type', 'multiplier', 'conditions', 'priority', 'is_active']));

        return response()->json([
            'message' => 'Surge rule updated successfully.',
            'surge_rule' => new SurgeRuleResource($surgeRule->load(['city', 'vehicleClass'])),
        ]);
    }

    public function toggleStatus(Request $request, SurgeRule $surgeRule): JsonResponse
    {
        $admin = $request->user();

        $surgeRule->update(['is_active' => ! $surgeRule->is_active]);

        AuditLog::record($surgeRule, 'surge_rule_toggled', $admin, null, [
            'is_active' => $surgeRule->is_active,
        ]);

        return response()->json([
            'message' => $surgeRule->is_active ? 'Surge rule activated.' : 'Surge rule deactivated.',
            'surge_rule' => new SurgeRuleResource($surgeRule),
        ]);
    }

    public function currentMultiplier(Request $request, SurgePricingService $surgePricingService): JsonResponse
    {
        $request->validate([
            'city_id' => ['required', 'uuid', 'exists:cities,id'],
            'vehicle_class_id' => ['nullable', 'uuid', 'exists:vehicle_classes,id'],
        ]);

        $surge = $surgePricingService->getCurrentMultiplier(
            $request->input('city_id'),
            $request->input('vehicle_class_id'),
        );

        return response()->json([
            'surge' => [
                'active' => $surge['multiplier'] > 1.0,
                'multiplier' => $surge['multiplier'],
                'rule_name' => $surge['rule']?->name,
                'rule' => $surge['rule'] ? new SurgeRuleResource($surge['rule']) : null,
            ],
        ]);
    }
}
