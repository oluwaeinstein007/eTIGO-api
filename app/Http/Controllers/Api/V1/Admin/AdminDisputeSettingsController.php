<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super admin settings for advanced disputes (OQ-21): refund limits per
 * tier, response targets, tier 1 auto-escalation, the appeal window and the
 * evidence limit. Super admin only: the limits bind the people who would
 * otherwise change them. New targets apply to cases entering a tier after
 * the change.
 */
class AdminDisputeSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => AppSetting::disputes(),
            'tiers' => config('disputes.tiers'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tier_1_refund_limit' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
            'tier_2_refund_limit' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
            'safety_refund_limit' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
            'safety_first_response_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'safety_resolution_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'tier_1_first_response_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'tier_1_resolution_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'tier_2_first_response_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'tier_2_resolution_hours' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'auto_escalate_tier_1' => ['sometimes', 'boolean'],
            'appeal_window_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'max_evidence_files' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        $current = AppSetting::disputes();
        $next = array_merge($current, $validated);

        if ($next['tier_2_refund_limit'] < $next['tier_1_refund_limit']) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['tier_2_refund_limit' => ['The tier 2 limit cannot be below the tier 1 limit.']],
            ], 422);
        }

        DB::transaction(function () use ($validated, $current, $request) {
            AuditLog::record(
                $request->user(),
                'dispute_settings.updated',
                $request->user(),
                array_intersect_key($current, $validated),
                $validated,
            );

            foreach ($validated as $key => $value) {
                AppSetting::setValue(
                    "disputes.{$key}",
                    is_bool($value) ? ($value ? 'true' : 'false') : $value,
                    'disputes',
                );
            }
        });

        return response()->json([
            'message' => 'Dispute settings updated.',
            'settings' => AppSetting::disputes(),
        ]);
    }
}
