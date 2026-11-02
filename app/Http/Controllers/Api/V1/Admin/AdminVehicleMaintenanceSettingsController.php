<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FuelType;
use App\Enums\MaintenanceItemType;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operations settings for vehicle maintenance (OQ-19): the intervals per
 * item (and per fuel type), the reminder lead, and the grace period before
 * expired papers keep a car off the road. Each car's due dates are
 * recomputed on its next check (daily, or when it is opened).
 *
 * A fuel type's own interval for an item overrides the main one. Sending
 * null (or neither km nor months) for it removes it, and null for the whole
 * fuel type removes all of that fuel type's own intervals, so those items
 * go back to the main intervals.
 */
class AdminVehicleMaintenanceSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => $this->present(AppSetting::vehicleMaintenance()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $recordable = array_map(fn (MaintenanceItemType $type) => $type->value, MaintenanceItemType::recordable());
        $fuelTypes = array_column(FuelType::cases(), 'value');

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'intervals' => ['sometimes', 'array:'.implode(',', $recordable)],
            'intervals.*' => ['array:km,months'],
            'intervals.*.km' => ['nullable', 'integer', 'min:500', 'max:100000'],
            'intervals.*.months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'fuel_type_intervals' => ['sometimes', 'array:'.implode(',', $fuelTypes)],
            'fuel_type_intervals.*' => ['nullable', 'array:'.implode(',', $recordable)],
            'fuel_type_intervals.*.*' => ['nullable', 'array:km,months'],
            'fuel_type_intervals.*.*.km' => ['nullable', 'integer', 'min:500', 'max:100000'],
            'fuel_type_intervals.*.*.months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'due_soon_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'due_soon_km' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'legal_grace_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'block_on_expired_papers' => ['sometimes', 'boolean'],
        ]);

        $current = AppSetting::vehicleMaintenance();

        // A new intervals map replaces the old one item by item.
        if (isset($validated['intervals'])) {
            $validated['intervals'] = [...$current['intervals'], ...$validated['intervals']];

            foreach ($validated['intervals'] as $type => $interval) {
                if (empty($interval['km']) && empty($interval['months'])) {
                    return response()->json([
                        'message' => 'Each item needs a km or a months interval.',
                        'errors' => ["intervals.{$type}" => ['Give a km or a months interval.']],
                    ], 422);
                }
            }
        }

        // Fuel type overrides merge per fuel type and item, like intervals.
        // An empty one is removed rather than stored (it would never be due),
        // and a fuel type left with none drops out.
        if (isset($validated['fuel_type_intervals'])) {
            $merged = $current['fuel_type_intervals'];

            foreach ($validated['fuel_type_intervals'] as $fuelType => $types) {
                foreach ($types ?? array_fill_keys(array_keys($merged[$fuelType] ?? []), null) as $type => $interval) {
                    if (empty($interval['km']) && empty($interval['months'])) {
                        unset($merged[$fuelType][$type]);
                    } else {
                        $merged[$fuelType][$type] = ['km' => $interval['km'] ?? null, 'months' => $interval['months'] ?? null];
                    }
                }

                if (empty($merged[$fuelType])) {
                    unset($merged[$fuelType]);
                }
            }

            $validated['fuel_type_intervals'] = $merged;
        }

        DB::transaction(function () use ($validated, $current, $request) {
            AuditLog::record(
                $request->user(),
                'vehicle_maintenance_settings.updated',
                $request->user(),
                array_intersect_key($current, $validated),
                $validated,
            );

            foreach ($validated as $key => $value) {
                AppSetting::setValue(
                    "vehicle_maintenance.{$key}",
                    match (true) {
                        is_bool($value) => $value ? 'true' : 'false',
                        is_array($value) => json_encode($value),
                        default => $value,
                    },
                    'vehicle_maintenance',
                );
            }
        });

        return response()->json([
            'message' => 'Vehicle maintenance settings updated.',
            'settings' => $this->present(AppSetting::vehicleMaintenance()),
        ]);
    }

    /**
     * The interval maps as JSON objects keyed by item and fuel type, never
     * as lists, even when empty.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function present(array $settings): array
    {
        $settings['intervals'] = (object) $settings['intervals'];
        $settings['fuel_type_intervals'] = (object) array_map(
            fn (array $types) => (object) $types,
            $settings['fuel_type_intervals'],
        );

        return $settings;
    }
}
