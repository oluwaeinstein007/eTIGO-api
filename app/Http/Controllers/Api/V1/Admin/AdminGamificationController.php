<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TierLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gamification\UpdateMultiplierConfigFormRequest;
use App\Http\Requests\Gamification\UpdateTierConfigFormRequest;
use App\Http\Resources\GamificationResource;
use App\Http\Resources\PointMultiplierConfigResource;
use App\Http\Resources\TierConfigResource;
use App\Models\AuditLog;
use App\Models\GamificationProfile;
use App\Models\PointMultiplierConfig;
use App\Models\TierConfig;
use App\Models\TripCarbonScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminGamificationController extends Controller
{
    public function indexUsers(Request $request): JsonResponse
    {
        $query = GamificationProfile::with('user');

        if ($request->filled('tier')) {
            $query->where('current_tier', $request->input('tier'));
        }

        if ($request->filled('search')) {
            $search = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $request->input('search'));
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('min_points')) {
            $query->where('total_ranking_points', '>=', $request->integer('min_points'));
        }

        $sortBy = $request->input('sort_by', 'total_ranking_points');
        $sortDir = $request->input('sort_dir', 'desc');

        if (in_array($sortBy, ['total_ranking_points', 'total_carbon_score', 'current_tier', 'created_at'])) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $profiles = $query->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json([
            'profiles' => GamificationResource::collection($profiles),
            'meta' => [
                'current_page' => $profiles->currentPage(),
                'last_page' => $profiles->lastPage(),
                'per_page' => $profiles->perPage(),
                'total' => $profiles->total(),
            ],
        ]);
    }

    public function aggregate(): JsonResponse
    {
        $tierDistribution = GamificationProfile::select('current_tier', DB::raw('count(*) as count'))
            ->groupBy('current_tier')
            ->orderBy('current_tier')
            ->get()
            ->map(fn ($row) => [
                'tier_level' => $row->current_tier,
                'tier_name' => TierLevel::tryFrom($row->current_tier)?->label() ?? 'Unknown',
                'count' => $row->count,
            ]);

        $averageCarbonScore = GamificationProfile::avg('total_carbon_score');
        $averageRankingPoints = GamificationProfile::avg('total_ranking_points');
        $totalProfiles = GamificationProfile::count();

        $topUsers = GamificationProfile::with('user')
            ->orderByDesc('total_ranking_points')
            ->limit(10)
            ->get()
            ->map(fn (GamificationProfile $p) => [
                'user_id' => $p->user_id,
                'first_name' => $p->user?->first_name,
                'last_name' => $p->user?->last_name,
                'tier' => $p->current_tier,
                'tier_name' => $p->tierLevel()->label(),
                'total_ranking_points' => $p->total_ranking_points,
                'total_carbon_score' => (float) $p->total_carbon_score,
            ]);

        $totalCo2Saved = TripCarbonScore::sum('co2_saved');

        return response()->json([
            'aggregate' => [
                'total_profiles' => $totalProfiles,
                'average_carbon_score' => round((float) ($averageCarbonScore ?? 0), 2),
                'average_ranking_points' => round((float) ($averageRankingPoints ?? 0), 0),
                'total_co2_saved_kg' => round((float) $totalCo2Saved, 2),
                'tier_distribution' => $tierDistribution,
                'top_users' => $topUsers,
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

    public function updateTiers(UpdateTierConfigFormRequest $request): JsonResponse
    {
        $admin = $request->user();

        DB::transaction(function () use ($request, $admin) {
            foreach ($request->validated('tiers') as $tierData) {
                $tier = TierConfig::where('tier_level', $tierData['tier_level'])->first();

                if ($tier) {
                    $oldValues = $tier->toArray();

                    $tier->update([
                        'tier_name' => $tierData['tier_name'],
                        'min_points_required' => $tierData['min_points_required'],
                        'booking_fee_discount_pct' => $tierData['booking_fee_discount_pct'],
                        'ev_reservation_fee_waived' => $tierData['ev_reservation_fee_waived'],
                        'priority_matching_enabled' => $tierData['priority_matching_enabled'],
                    ]);

                    AuditLog::record($tier, 'tier_config_updated', $admin, $oldValues, $tierData);
                }
            }
        });

        $tiers = TierConfig::allOrderedByLevel();

        return response()->json([
            'message' => 'Tier configuration updated successfully.',
            'tiers' => TierConfigResource::collection($tiers),
        ]);
    }

    public function multipliers(): JsonResponse
    {
        $multipliers = PointMultiplierConfig::all();

        return response()->json([
            'multipliers' => PointMultiplierConfigResource::collection($multipliers),
        ]);
    }

    public function updateMultipliers(UpdateMultiplierConfigFormRequest $request): JsonResponse
    {
        $admin = $request->user();

        DB::transaction(function () use ($request, $admin) {
            foreach ($request->validated('multipliers') as $data) {
                $config = PointMultiplierConfig::where('condition_type', $data['condition_type'])->first();

                if ($config) {
                    $oldValues = $config->toArray();

                    $config->update([
                        'multiplier_value' => $data['multiplier_value'],
                        'is_stackable' => $data['is_stackable'],
                    ]);

                    AuditLog::record($config, 'multiplier_config_updated', $admin, $oldValues, $data);
                } else {
                    $config = PointMultiplierConfig::create($data);

                    AuditLog::record($config, 'multiplier_config_created', $admin, null, $data);
                }
            }
        });

        $multipliers = PointMultiplierConfig::all();

        return response()->json([
            'message' => 'Multiplier configuration updated successfully.',
            'multipliers' => PointMultiplierConfigResource::collection($multipliers),
        ]);
    }
}
