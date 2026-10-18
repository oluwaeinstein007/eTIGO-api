<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operations settings for Lost & Found (Phase 2): the report window, the
 * timings that hand a report to support or close it, contact sharing and
 * the return fee. A new return fee applies to items confirmed afterwards;
 * each report keeps the fee it was quoted.
 */
class AdminLostAndFoundSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => AppSetting::lostAndFound(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'report_window_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'max_open_reports_per_passenger' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'driver_response_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'return_deadline_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'auto_resolve_hours' => ['sometimes', 'integer', 'min:1', 'max:336'],
            'denied_auto_close_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'share_contact_details' => ['sometimes', 'boolean'],
            'return_fee' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
        ]);

        $current = AppSetting::lostAndFound();

        DB::transaction(function () use ($validated, $current, $request) {
            AuditLog::record(
                $request->user(),
                'lost_and_found_settings.updated',
                $request->user(),
                array_intersect_key($current, $validated),
                $validated,
            );

            foreach ($validated as $key => $value) {
                AppSetting::setValue(
                    "lost_and_found.{$key}",
                    is_bool($value) ? ($value ? 'true' : 'false') : $value,
                    'lost_and_found',
                );
            }
        });

        return response()->json([
            'message' => 'Lost & Found settings updated.',
            'settings' => AppSetting::lostAndFound(),
        ]);
    }
}
