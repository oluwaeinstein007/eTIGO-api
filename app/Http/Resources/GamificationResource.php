<?php

namespace App\Http\Resources;

use App\Enums\TierLevel;
use App\Models\TierConfig;
use App\Models\TripCarbonScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GamificationResource extends JsonResource
{
    private static ?array $tierConfigCache = null;

    private static ?array $scoreTotalsCache = null;

    private static function tierConfigs(): array
    {
        if (self::$tierConfigCache === null) {
            self::$tierConfigCache = TierConfig::all()->keyBy('tier_level')->all();
        }

        return self::$tierConfigCache;
    }

    /**
     * @param  array<int>  $userIds
     */
    public static function preloadScoreTotals(array $userIds): void
    {
        if (empty($userIds)) {
            self::$scoreTotalsCache = [];

            return;
        }

        $startOfMonth = now()->startOfMonth();

        $lifetime = TripCarbonScore::whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(co2_saved) as total_co2')
            ->pluck('total_co2', 'user_id');

        $monthly = TripCarbonScore::whereIn('user_id', $userIds)
            ->where('created_at', '>=', $startOfMonth)
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(co2_saved) as month_co2, sum(final_points) as month_points')
            ->get()
            ->keyBy('user_id');

        self::$scoreTotalsCache = [];
        foreach ($userIds as $uid) {
            self::$scoreTotalsCache[$uid] = [
                'total_co2' => (float) ($lifetime[$uid] ?? 0),
                'month_co2' => (float) ($monthly[$uid]->month_co2 ?? 0),
                'month_points' => (int) ($monthly[$uid]->month_points ?? 0),
            ];
        }
    }

    public function toArray(Request $request): array
    {
        $currentTier = TierLevel::from($this->current_tier);
        $nextTier = $currentTier->next();

        $configs = self::tierConfigs();

        $nextTierConfig = $nextTier
            ? ($configs[$nextTier->value] ?? null)
            : null;

        $currentTierConfig = $configs[$this->current_tier] ?? null;

        $progressToNext = null;
        if ($nextTierConfig) {
            $currentThreshold = $currentTierConfig?->min_points_required ?? 0;
            $nextThreshold = $nextTierConfig->min_points_required;
            $pointsInTier = $this->total_ranking_points - $currentThreshold;
            $tierRange = $nextThreshold - $currentThreshold;
            $progressToNext = $tierRange > 0
                ? min(100, round(($pointsInTier / $tierRange) * 100, 1))
                : 100;
        }

        if (isset(self::$scoreTotalsCache[$this->user_id])) {
            $totals = self::$scoreTotalsCache[$this->user_id];
            $totalCo2Saved = $totals['total_co2'];
            $co2SavedThisMonth = $totals['month_co2'];
            $pointsThisMonth = $totals['month_points'];
        } else {
            $co2Query = TripCarbonScore::where('user_id', $this->user_id);
            $totalCo2Saved = (float) (clone $co2Query)->sum('co2_saved');
            $co2SavedThisMonth = (float) (clone $co2Query)
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('co2_saved');
            $pointsThisMonth = (int) TripCarbonScore::where('user_id', $this->user_id)
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('final_points');
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'first_name' => $this->whenLoaded('user', fn () => $this->user?->first_name),
            'last_name' => $this->whenLoaded('user', fn () => $this->user?->last_name),
            'email' => $this->whenLoaded('user', fn () => $this->user?->email),
            'city_id' => $this->when($this->hasAttribute('city_name'), fn () => $this->city_id),
            'city_name' => $this->when($this->hasAttribute('city_name'), fn () => $this->city_name),
            'current_tier' => $this->current_tier,
            'tier_name' => $currentTier->label(),
            'total_ranking_points' => $this->total_ranking_points,
            'total_carbon_score' => (float) $this->total_carbon_score,
            'total_co2_saved_kg' => round($totalCo2Saved, 2),
            'co2_saved_this_month_kg' => round($co2SavedThisMonth, 2),
            'points_this_month' => $pointsThisMonth,
            'tier_upgraded_at' => $this->tier_upgraded_at,
            'progress_to_next_tier' => $progressToNext,
            'points_to_next_tier' => $nextTierConfig
                ? max(0, $nextTierConfig->min_points_required - $this->total_ranking_points)
                : null,
            'next_tier' => $nextTier?->label(),
            'benefits' => $this->when($currentTierConfig, fn () => [
                'booking_fee_discount_pct' => (float) $currentTierConfig->booking_fee_discount_pct,
                'priority_matching' => $currentTierConfig->priority_matching_enabled,
                'ev_reservation_fee_waived' => $currentTierConfig->ev_reservation_fee_waived,
                'exclusive_promotions' => $currentTierConfig->exclusive_promotions_enabled,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
