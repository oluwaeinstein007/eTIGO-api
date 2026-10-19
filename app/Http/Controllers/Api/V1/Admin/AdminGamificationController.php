<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TierLevel;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gamification\UpdateMultiplierConfigFormRequest;
use App\Http\Requests\Gamification\UpdateTierConfigFormRequest;
use App\Http\Resources\GamificationResource;
use App\Http\Resources\PointMultiplierConfigResource;
use App\Http\Resources\TierConfigResource;
use App\Models\AuditLog;
use App\Models\GamificationProfile;
use App\Models\PointMultiplierConfig;
use App\Models\Ride;
use App\Models\TierConfig;
use App\Models\TripCarbonScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AdminGamificationController extends Controller
{
    public function indexUsers(Request $request): JsonResponse
    {
        $latestRide = fn (string $column) => Ride::select($column)
            ->join('cities', 'cities.id', '=', 'rides.city_id')
            ->whereColumn('rides.passenger_id', 'gamification_profiles.user_id')
            ->latest('rides.created_at')
            ->limit(1);

        $query = GamificationProfile::with('user')
            ->whereHas('user', fn ($q) => $q->where('type', UserType::Passenger))
            ->select('gamification_profiles.*')
            ->addSelect([
                'city_id' => $latestRide('cities.id'),
                'city_name' => $latestRide('cities.name'),
            ]);

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

        if ($request->filled('city_id')) {
            $cityId = $request->input('city_id');
            $query->whereHas('user', fn ($q) => $q->whereHas('rides', fn ($r) => $r->where('city_id', $cityId)));
        }

        $sortBy = $request->input('sort_by', 'total_ranking_points');
        $sortDir = $request->input('sort_dir', 'desc');

        if (in_array($sortBy, ['total_ranking_points', 'total_carbon_score', 'current_tier', 'created_at'])) {
            $query->orderBy("gamification_profiles.{$sortBy}", $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $profiles = $query->paginate(min(max($request->integer('per_page', 15), 1), 100));

        GamificationResource::preloadScoreTotals($profiles->pluck('user_id')->all());

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

    public function aggregate(Request $request): JsonResponse
    {
        $profileQuery = GamificationProfile::whereHas('user', fn ($q) => $q->where('type', UserType::Passenger));
        $carbonQuery = TripCarbonScore::whereHas('user', fn ($q) => $q->where('type', UserType::Passenger));

        if ($request->filled('city_id')) {
            $cityId = $request->input('city_id');
            $profileQuery->whereHas('user', fn ($q) => $q->whereHas('rides', fn ($r) => $r->where('city_id', $cityId)));
            $carbonQuery->whereHas('ride', fn ($q) => $q->where('city_id', $cityId));
        }

        if ($request->filled('from')) {
            $carbonQuery->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $carbonQuery->whereDate('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('from') || $request->filled('to')) {
            $profileQuery->whereHas('carbonScores', function ($q) use ($request) {
                if ($request->filled('from')) {
                    $q->whereDate('created_at', '>=', $request->input('from'));
                }
                if ($request->filled('to')) {
                    $q->whereDate('created_at', '<=', $request->input('to'));
                }
            });
        }

        $tierDistribution = (clone $profileQuery)
            ->select('current_tier', DB::raw('count(*) as count'))
            ->groupBy('current_tier')
            ->orderBy('current_tier')
            ->get()
            ->map(fn ($row) => [
                'tier_level' => $row->current_tier,
                'tier_name' => TierLevel::tryFrom($row->current_tier)?->label() ?? 'Unknown',
                'count' => $row->count,
            ]);

        $averageCarbonScore = (clone $profileQuery)->avg('total_carbon_score');
        $averageRankingPoints = (clone $profileQuery)->avg('total_ranking_points');
        $totalProfiles = (clone $profileQuery)->count();

        $topUsers = (clone $profileQuery)->with('user')
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

        $totalCo2Saved = (clone $carbonQuery)->sum('co2_saved');
        $totalPointsAwarded = (clone $carbonQuery)->sum('final_points');
        $totalCarbonTrips = (clone $carbonQuery)->count();
        $averageCo2PerTrip = $totalCarbonTrips > 0
            ? round((float) $totalCo2Saved / $totalCarbonTrips, 4)
            : 0;

        return response()->json([
            'aggregate' => [
                'total_profiles' => $totalProfiles,
                'average_carbon_score' => round((float) ($averageCarbonScore ?? 0), 2),
                'average_ranking_points' => round((float) ($averageRankingPoints ?? 0), 0),
                'total_co2_saved_kg' => round((float) $totalCo2Saved, 2),
                'total_points_awarded' => (int) $totalPointsAwarded,
                'average_co2_per_trip' => $averageCo2PerTrip,
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

                    $tier->update(Arr::except($tierData, 'tier_level'));

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
