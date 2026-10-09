<?php

namespace App\Services;

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

    public function getUserTierLevel(string $userId): int
    {
        $profile = GamificationProfile::where('user_id', $userId)->first();

        return $profile?->current_tier ?? 1;
    }

    /**
     * @return array{tier_level: int, tier_name: string, priority_matching: bool, booking_fee_discount_pct: float, ev_fee_waived: bool}
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
            ];
        }

        return [
            'tier_level' => $tierConfig->tier_level,
            'tier_name' => $tierConfig->tier_name,
            'priority_matching' => $tierConfig->priority_matching_enabled,
            'booking_fee_discount_pct' => (float) $tierConfig->booking_fee_discount_pct,
            'ev_fee_waived' => $tierConfig->ev_reservation_fee_waived,
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
