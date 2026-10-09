<?php

namespace Database\Seeders;

use App\Models\TierConfig;
use Illuminate\Database\Seeder;

class TierConfigSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'tier_level' => 1,
                'tier_name' => 'Bronze',
                'min_points_required' => 0,
                'booking_fee_discount_pct' => 0,
                'ev_reservation_fee_waived' => false,
                'priority_matching_enabled' => false,
            ],
            [
                'tier_level' => 2,
                'tier_name' => 'Silver',
                'min_points_required' => 500,
                'booking_fee_discount_pct' => 2.00,
                'ev_reservation_fee_waived' => false,
                'priority_matching_enabled' => false,
            ],
            [
                'tier_level' => 3,
                'tier_name' => 'Gold',
                'min_points_required' => 2000,
                'booking_fee_discount_pct' => 5.00,
                'ev_reservation_fee_waived' => false,
                'priority_matching_enabled' => true,
            ],
            [
                'tier_level' => 4,
                'tier_name' => 'Platinum',
                'min_points_required' => 5000,
                'booking_fee_discount_pct' => 10.00,
                'ev_reservation_fee_waived' => true,
                'priority_matching_enabled' => true,
            ],
            [
                'tier_level' => 5,
                'tier_name' => 'Diamond',
                'min_points_required' => 15000,
                'booking_fee_discount_pct' => 15.00,
                'ev_reservation_fee_waived' => true,
                'priority_matching_enabled' => true,
            ],
        ];

        foreach ($tiers as $tier) {
            TierConfig::updateOrCreate(
                ['tier_level' => $tier['tier_level']],
                $tier,
            );
        }
    }
}
