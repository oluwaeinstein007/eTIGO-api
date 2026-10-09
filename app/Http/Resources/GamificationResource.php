<?php

namespace App\Http\Resources;

use App\Enums\TierLevel;
use App\Models\TierConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GamificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currentTier = TierLevel::from($this->current_tier);
        $nextTier = $currentTier->next();

        $nextTierConfig = $nextTier
            ? TierConfig::forLevel($nextTier->value)
            : null;

        $currentTierConfig = TierConfig::forLevel($this->current_tier);

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

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'current_tier' => $this->current_tier,
            'tier_name' => $currentTier->label(),
            'total_ranking_points' => $this->total_ranking_points,
            'total_carbon_score' => (float) $this->total_carbon_score,
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
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
