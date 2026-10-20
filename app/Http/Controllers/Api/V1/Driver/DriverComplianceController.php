<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfflineFlagResource;
use App\Models\OfflineTripFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverComplianceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $activeFlags = OfflineTripFlag::forDriver($user->id)
            ->withinLookback()
            ->notOverturned()
            ->count();

        $totalFlags = OfflineTripFlag::forDriver($user->id)->count();

        $lookbackDays = (int) config('offline_detection.sanction_lookback_days', 30);

        // The flag behind the driver's current sanction: the latest one that
        // still counts, whether or not an admin has upheld it.
        $latestFlag = OfflineTripFlag::forDriver($user->id)
            ->notOverturned()
            ->latest('flagged_at')
            ->first();

        $recentFlags = OfflineTripFlag::forDriver($user->id)
            ->with(['ride'])
            ->latest('flagged_at')
            ->limit(10)
            ->get();

        $activeSanction = null;
        if ($latestFlag && ($latestFlag->hasStandingDeactivation() || $latestFlag->flagged_at->gte(now()->subDays($lookbackDays)))) {
            $activeSanction = [
                'flag_id' => $latestFlag->id,
                'tier' => [
                    'value' => $latestFlag->sanction_tier->value,
                    'label' => $latestFlag->sanction_tier->label(),
                ],
                'action' => $latestFlag->sanction_action,
                'status' => [
                    'value' => $latestFlag->status()->value,
                    'label' => $latestFlag->status()->label(),
                ],
                'flagged_at' => $latestFlag->flagged_at?->toISOString(),
                'is_disputed' => $latestFlag->is_disputed,
                'deactivation_confirmed' => $latestFlag->hasStandingDeactivation(),
            ];
        }

        return response()->json([
            'message' => 'Compliance status retrieved.',
            'compliance' => [
                'active_flags_count' => $activeFlags,
                'total_flags_count' => $totalFlags,
                'active_sanction' => $activeSanction,
                'lookback_days' => $lookbackDays,
            ],
            'recent_flags' => OfflineFlagResource::collection($recentFlags),
        ]);
    }
}
