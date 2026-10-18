<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operations settings for launching cities (Phase 2, OQ-22): the driver
 * minimum for a first launch, whether plug-in drivers need a charging
 * station, and what a pause does to upcoming bookings by default.
 */
class AdminCitySettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => AppSetting::cities(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'launch_min_approved_drivers' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'require_ev_charging' => ['sometimes', 'boolean'],
            'pause_scheduled_rides' => ['sometimes', 'string', 'in:honour,cancel'],
        ]);

        $current = AppSetting::cities();

        DB::transaction(function () use ($validated, $current, $request) {
            AuditLog::record(
                $request->user(),
                'city_settings.updated',
                $request->user(),
                array_intersect_key($current, $validated),
                $validated,
            );

            foreach ($validated as $key => $value) {
                AppSetting::setValue(
                    "cities.{$key}",
                    is_bool($value) ? ($value ? 'true' : 'false') : $value,
                    'cities',
                );
            }
        });

        return response()->json([
            'message' => 'City settings updated.',
            'settings' => AppSetting::cities(),
        ]);
    }
}
