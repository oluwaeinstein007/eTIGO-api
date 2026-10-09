<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CarbonScoreResource;
use App\Http\Resources\GamificationResource;
use App\Http\Resources\TierConfigResource;
use App\Models\GamificationProfile;
use App\Models\TierConfig;
use App\Models\TripCarbonScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GamificationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = GamificationProfile::findOrCreateForUser($user->id);

        return response()->json([
            'gamification' => new GamificationResource($profile),
        ]);
    }

    public function carbonHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        $scores = TripCarbonScore::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json([
            'carbon_scores' => CarbonScoreResource::collection($scores),
            'meta' => [
                'current_page' => $scores->currentPage(),
                'last_page' => $scores->lastPage(),
                'per_page' => $scores->perPage(),
                'total' => $scores->total(),
            ],
        ]);
    }

    public function tiers(): JsonResponse
    {
        $tiers = TierConfig::allOrderedByLevel();

        return response()->json([
            'tiers' => TierConfigResource::collection($tiers),
        ]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $profiles = GamificationProfile::with('user')
            ->orderByDesc('total_ranking_points')
            ->limit(min(max($request->integer('limit', 20), 1), 100))
            ->get();

        return response()->json([
            'leaderboard' => $profiles->map(fn (GamificationProfile $p) => [
                'user_id' => $p->user_id,
                'first_name' => $p->user?->first_name,
                'tier' => $p->current_tier,
                'tier_name' => $p->tierLevel()->label(),
                'total_ranking_points' => $p->total_ranking_points,
                'total_carbon_score' => (float) $p->total_carbon_score,
            ]),
        ]);
    }
}
