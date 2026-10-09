<?php

namespace Database\Seeders;

use App\Enums\MultiplierConditionType;
use App\Models\PointMultiplierConfig;
use Illuminate\Database\Seeder;

class PointMultiplierConfigSeeder extends Seeder
{
    public function run(): void
    {
        $multipliers = [
            [
                'condition_type' => MultiplierConditionType::EvRide->value,
                'multiplier_value' => 2.00,
                'is_stackable' => true,
            ],
            [
                'condition_type' => MultiplierConditionType::SharedJourney->value,
                'multiplier_value' => 1.50,
                'is_stackable' => true,
            ],
            [
                'condition_type' => MultiplierConditionType::OffPeak->value,
                'multiplier_value' => 1.25,
                'is_stackable' => true,
            ],
        ];

        foreach ($multipliers as $multiplier) {
            PointMultiplierConfig::updateOrCreate(
                ['condition_type' => $multiplier['condition_type']],
                $multiplier,
            );
        }
    }
}
