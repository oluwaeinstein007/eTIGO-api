<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TierLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\CarbonScoreResource;
use App\Http\Resources\GamificationResource;
use App\Http\Resources\TierConfigResource;
use App\Models\GamificationProfile;
use App\Models\TierConfig;
use App\Models\TripCarbonScore;
use Barryvdh\DomPDF\Facade\Pdf;
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

    public function tiers(Request $request): JsonResponse
    {
        $tiers = TierConfig::allOrderedByLevel();

        $currentTier = 1;
        if ($request->user()) {
            $profile = GamificationProfile::where('user_id', $request->user()->id)->first();
            $currentTier = $profile?->current_tier ?? 1;
        }

        $tiersData = $tiers->map(function ($tier) use ($currentTier) {
            $resource = (new TierConfigResource($tier))->resolve();
            $resource['unlocked'] = $tier->level <= $currentTier;

            return $resource;
        });

        return response()->json([
            'tiers' => $tiersData,
            'current_tier' => $currentTier,
            'current_tier_name' => TierLevel::from($currentTier)->label(),
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

    public function greenImpactPdf(Request $request)
    {
        $user = $request->user();
        $profile = GamificationProfile::findOrCreateForUser($user->id);

        $recentScores = TripCarbonScore::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $totalRides = TripCarbonScore::where('user_id', $user->id)->count();
        $totalCo2Saved = (float) TripCarbonScore::where('user_id', $user->id)->sum('co2_saved_kg');
        $electricKm = (float) TripCarbonScore::where('user_id', $user->id)->sum('distance_km');

        $pdf = Pdf::loadView('pdf.green-impact', [
            'user' => $user,
            'profile' => $profile,
            'tierName' => $profile->tierLevel()->label(),
            'totalPoints' => $profile->total_ranking_points,
            'totalRides' => $totalRides,
            'totalCo2Saved' => $totalCo2Saved,
            'electricKm' => $electricKm,
            'recentScores' => $recentScores,
        ]);

        return $pdf->download("etigo-green-impact-{$user->id}.pdf");
    }
}
