<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CityLaunchStatus;
use App\Enums\CityReadinessGate;
use App\Exceptions\CityLaunchException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Models\City;
use App\Services\CityLaunchService;
use App\Services\CityReadinessService;
use App\Services\PricingCoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Launching, pausing and resuming cities (Phase 2 multi-city, OQ-22), and
 * the readiness checklist a first launch needs.
 */
class AdminCityLaunchController extends Controller
{
    public function __construct(
        private CityReadinessService $readiness,
        private CityLaunchService $launch,
        private PricingCoverageService $coverage,
    ) {}

    public function readiness(City $city): JsonResponse
    {
        return response()->json([
            'city' => new CityResource($city),
            'readiness' => $this->readiness->report($city),
        ]);
    }

    /**
     * Confirm or clear a manual gate (support_cover, permits).
     */
    public function confirmGate(Request $request, City $city, string $gate): JsonResponse
    {
        $gate = CityReadinessGate::tryFrom($gate);

        if ($gate === null || ! $gate->isManual()) {
            return response()->json([
                'message' => 'Only these gates are confirmed by an admin: '
                    .implode(', ', array_map(fn (CityReadinessGate $g) => $g->value, CityReadinessGate::manual())).'.',
            ], 422);
        }

        $validated = $request->validate([
            'confirmed' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'value' => [
                Rule::requiredIf($gate === CityReadinessGate::Permits && $request->boolean('confirmed', true)),
                'nullable',
                'string',
                Rule::in($gate === CityReadinessGate::Permits ? CityReadinessService::PERMIT_VALUES : []),
            ],
        ]);

        $confirmed = $validated['confirmed'] ?? true;

        $city = $this->readiness->confirm(
            $city,
            $gate,
            $request->user(),
            $confirmed,
            $validated['note'] ?? null,
            $validated['value'] ?? null,
        );

        return response()->json([
            'message' => $confirmed ? 'Readiness gate confirmed.' : 'Readiness gate cleared.',
            'readiness' => $this->readiness->report($city),
        ]);
    }

    /**
     * Move the city to onboarding, live or paused.
     */
    public function update(Request $request, City $city): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(CityLaunchStatus::class)],
            'reason' => ['required_if:status,paused', 'nullable', 'string', 'max:500'],
            'scheduled_rides' => ['nullable', 'string', 'in:honour,cancel', 'prohibited_unless:status,paused'],
        ]);

        try {
            $result = $this->launch->changeStatus(
                $city,
                CityLaunchStatus::from($validated['status']),
                $request->user(),
                $validated['reason'] ?? null,
                $validated['scheduled_rides'] ?? null,
            );
        } catch (CityLaunchException $e) {
            return response()->json(array_filter([
                'message' => $e->getMessage(),
                'readiness' => $e->readiness,
            ]), 422);
        }

        $city = $result['city'];

        return response()->json([
            'message' => match ($city->launch_status) {
                CityLaunchStatus::Live => $result['previous_status'] === CityLaunchStatus::Onboarding
                    ? "{$city->name} is live."
                    : "{$city->name} has resumed.",
                CityLaunchStatus::Paused => "{$city->name} is paused.",
                CityLaunchStatus::Onboarding => "{$city->name} is back in onboarding.",
            },
            'city' => new CityResource($city),
            'previous_status' => $result['previous_status']->value,
            'scheduled_rides' => $result['scheduled_rides'],
            'scheduled_rides_to_cancel' => $result['scheduled_rides_to_cancel'],
            'active_rides' => $result['active_rides'],
            'readiness' => $this->readiness->report($city),
            'warnings' => $this->coverage->warningsForCity($city),
        ]);
    }
}
