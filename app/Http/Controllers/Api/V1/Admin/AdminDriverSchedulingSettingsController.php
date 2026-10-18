<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operations settings for driver scheduling (OQ-20): the shift rules on
 * shared fleet cars and the limits on availability plans. Changes apply to
 * the next check; shifts already assigned keep their times.
 */
class AdminDriverSchedulingSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => AppSetting::driverScheduling(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shifts_enabled' => ['sometimes', 'boolean'],
            'shift_online_lead_minutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'shift_offer_cutoff_minutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'shift_missed_after_minutes' => ['sometimes', 'integer', 'min:5', 'max:240'],
            'shift_min_minutes' => ['sometimes', 'integer', 'min:15', 'max:720'],
            'shift_max_hours' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'shift_max_advance_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'availability_max_advance_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'availability_min_minutes' => ['sometimes', 'integer', 'min:15', 'max:720'],
            'availability_max_hours' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'availability_max_upcoming' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $current = AppSetting::driverScheduling();
        $merged = [...$current, ...$validated];

        if ($merged['shift_min_minutes'] > $merged['shift_max_hours'] * 60) {
            return response()->json([
                'message' => 'The shortest shift cannot be longer than the longest shift.',
                'errors' => ['shift_min_minutes' => ['The shortest shift cannot be longer than the longest shift.']],
            ], 422);
        }

        if ($merged['availability_min_minutes'] > $merged['availability_max_hours'] * 60) {
            return response()->json([
                'message' => 'The shortest plan cannot be longer than the longest plan.',
                'errors' => ['availability_min_minutes' => ['The shortest plan cannot be longer than the longest plan.']],
            ], 422);
        }

        DB::transaction(function () use ($validated, $current, $request) {
            AuditLog::record(
                $request->user(),
                'driver_scheduling_settings.updated',
                $request->user(),
                array_intersect_key($current, $validated),
                $validated,
            );

            foreach ($validated as $key => $value) {
                AppSetting::setValue(
                    "driver_scheduling.{$key}",
                    is_bool($value) ? ($value ? 'true' : 'false') : $value,
                    'driver_scheduling',
                );
            }
        });

        return response()->json([
            'message' => 'Driver scheduling settings updated.',
            'settings' => AppSetting::driverScheduling(),
        ]);
    }
}
