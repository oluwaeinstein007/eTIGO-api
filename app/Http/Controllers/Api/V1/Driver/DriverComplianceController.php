<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\DisputeOutcome;
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
            ->where(function ($q) {
                $q->where('is_disputed', false)
                    ->orWhere('dispute_outcome', '!=', DisputeOutcome::Overturned);
            })
            ->count();

        $totalFlags = OfflineTripFlag::forDriver($user->id)->count();

        $latestFlag = OfflineTripFlag::forDriver($user->id)
            ->latest('flagged_at')
            ->first();

        $recentFlags = OfflineTripFlag::forDriver($user->id)
            ->with(['ride'])
            ->latest('flagged_at')
            ->limit(10)
            ->get();

        $activeSanction = null;
        if ($latestFlag && ! $latestFlag->isResolved() && ! $latestFlag->isOverturned()) {
            $activeSanction = [
                'tier' => [
                    'value' => $latestFlag->sanction_tier->value,
                    'label' => $latestFlag->sanction_tier->label(),
                ],
                'action' => $latestFlag->sanction_action,
                'flagged_at' => $latestFlag->flagged_at?->toISOString(),
                'is_disputed' => $latestFlag->is_disputed,
            ];
        }

        return response()->json([
            'message' => 'Compliance status retrieved.',
            'compliance' => [
                'active_flags_count' => $activeFlags,
                'total_flags_count' => $totalFlags,
                'active_sanction' => $activeSanction,
                'lookback_days' => config('offline_detection.sanction_lookback_days', 30),
            ],
            'recent_flags' => OfflineFlagResource::collection($recentFlags),
        ]);
    }
}
