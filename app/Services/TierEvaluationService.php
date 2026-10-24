<?php

namespace App\Services;

use App\Events\TierUpgraded;
use App\Models\GamificationProfile;
use App\Models\TierConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TierEvaluationService
{
    /**
     * @return array{promoted: bool, old_tier: int, new_tier: int, tier_name: ?string}
     */
    public function evaluateAndPromote(GamificationProfile $profile, int $pointsToAdd, float $carbonToAdd): array
    {
        return DB::transaction(function () use ($profile, $pointsToAdd, $carbonToAdd) {
            $profile = GamificationProfile::lockForUpdate()->find($profile->id);
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

            // Broadcast once the outermost transaction commits; a WebSocket
            // outage must not fail the job after the points and tier are saved.
            DB::afterCommit(fn () => rescue(
                fn () => TierUpgraded::dispatch($profile->user_id, $oldTier, $newTierConfig->tier_level, $newTierConfig->tier_name),
                fn (\Throwable $e) => Log::warning('Tier upgrade broadcast failed', ['user_id' => $profile->user_id, 'error' => $e->getMessage()]),
                report: false,
            ));

            return [
                'promoted' => true,
                'old_tier' => $oldTier,
                'new_tier' => $newTierConfig->tier_level,
                'tier_name' => $newTierConfig->tier_name,
            ];
        });
    }

    private function determineNewTier(int $totalPoints): ?TierConfig
    {
        return TierConfig::where('min_points_required', '<=', $totalPoints)
            ->orderByDesc('tier_level')
            ->first();
    }
}
