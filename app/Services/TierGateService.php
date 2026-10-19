<?php

namespace App\Services;

use App\Enums\TierLevel;
use App\Models\GamificationProfile;
use App\Models\TierConfig;

class TierGateService
{
    public function hasPriorityMatching(string $userId): bool
    {
        $tierConfig = $this->getUserTierConfig($userId);

        return $tierConfig?->priority_matching_enabled ?? false;
    }

    public function getBookingFeeDiscount(string $userId): float
    {
        $tierConfig = $this->getUserTierConfig($userId);

        return $tierConfig ? (float) $tierConfig->booking_fee_discount_pct : 0.0;
    }

    public function isEvReservationFeeWaived(string $userId): bool
    {
        $tierConfig = $this->getUserTierConfig($userId);

        return $tierConfig?->ev_reservation_fee_waived ?? false;
    }

    /**
     * Whether the user can use a promo limited to $minTierLevel and up (G-07).
     * A Bronze minimum is open to everyone; above that the user's tier must
     * reach the minimum and have exclusive promotions switched on.
     */
    public function qualifiesForTierPromo(string $userId, ?int $minTierLevel): bool
    {
        if (! $minTierLevel || $minTierLevel <= TierLevel::Bronze->value) {
            return true;
        }

        $userTier = $this->getUserTierLevel($userId);

        if ($userTier < $minTierLevel) {
            return false;
        }

        // Tier configs are seeded on every deploy; without one, the minimum alone decides.
        return TierConfig::forLevel($userTier)?->exclusive_promotions_enabled ?? true;
    }

    public function getUserTierLevel(string $userId): int
    {
        $profile = GamificationProfile::where('user_id', $userId)->first();

        return $profile?->current_tier ?? 1;
    }

    /**
     * @return array{tier_level: int, tier_name: string, priority_matching: bool, booking_fee_discount_pct: float, ev_fee_waived: bool, exclusive_promotions: bool}
     */
    public function getUserBenefits(string $userId): array
    {
        $tierConfig = $this->getUserTierConfig($userId);

        if (! $tierConfig) {
            return [
                'tier_level' => 1,
                'tier_name' => 'Bronze',
                'priority_matching' => false,
                'booking_fee_discount_pct' => 0.0,
                'ev_fee_waived' => false,
                'exclusive_promotions' => false,
            ];
        }

        return [
            'tier_level' => $tierConfig->tier_level,
            'tier_name' => $tierConfig->tier_name,
            'priority_matching' => $tierConfig->priority_matching_enabled,
            'booking_fee_discount_pct' => (float) $tierConfig->booking_fee_discount_pct,
            'ev_fee_waived' => $tierConfig->ev_reservation_fee_waived,
            'exclusive_promotions' => $tierConfig->exclusive_promotions_enabled,
        ];
    }

    private function getUserTierConfig(string $userId): ?TierConfig
    {
        $profile = GamificationProfile::where('user_id', $userId)->first();

        if (! $profile) {
            return TierConfig::forLevel(1);
        }

        return TierConfig::forLevel($profile->current_tier);
    }
}
