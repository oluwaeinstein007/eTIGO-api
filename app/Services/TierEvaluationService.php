<?php

namespace App\Services;

use App\Events\TierUpgraded;
use App\Models\GamificationProfile;
use App\Models\TierConfig;

class TierEvaluationService
{
    /**
     * @return array{promoted: bool, old_tier: int, new_tier: int, tier_name: ?string}
     */
    public function evaluateAndPromote(GamificationProfile $profile, int $pointsToAdd, float $carbonToAdd): array
    {
        $oldTier = $profile->current_tier;

        $profile->increment('total_ranking_points', $pointsToAdd);
        $profile->increment('total_carbon_score', $carbonToAdd);
        $profile->refresh();

        $newTierConfig = $this->determineNewTier($profile->total_ranking_points);

        if (! $newTierConfig || $newTierConfig->tier_level <= $oldTier) {
            return [
                'promoted' => false,
                'old_tier' => $oldTier,
                'new_tier' => $profile->current_tier,
                'tier_name' => null,
            ];
        }

        $profile->update([
            'current_tier' => $newTierConfig->tier_level,
            'tier_upgraded_at' => now(),
        ]);

        TierUpgraded::dispatch(
            $profile->user_id,
            $oldTier,
            $newTierConfig->tier_level,
            $newTierConfig->tier_name,
        );

        return [
            'promoted' => true,
            'old_tier' => $oldTier,
            'new_tier' => $newTierConfig->tier_level,
            'tier_name' => $newTierConfig->tier_name,
        ];
    }

    private function determineNewTier(int $totalPoints): ?TierConfig
    {
        return TierConfig::where('min_points_required', '<=', $totalPoints)
            ->orderByDesc('tier_level')
            ->first();
    }
}
