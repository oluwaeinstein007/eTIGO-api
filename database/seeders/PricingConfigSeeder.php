<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\PricingConfig;
use App\Models\User;
use Database\Seeders\Data\NigerianCities;
use Illuminate\Database\Seeder;

/**
 * Pricing for every vehicle class each city offers, by the city's fare tier
 * (Data\NigerianCities): A for Lagos and Abuja, B for the large cities, C for
 * smaller state capitals, in line with ride-hailing fares there in 2026.
 * Waiting is free for 5 minutes everywhere (client-confirmed). Only missing
 * configs are created, so rates changed by admins are kept.
 */
class PricingConfigSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping PricingConfigSeeder in production.');

            return;
        }

        $admin = User::where('email', 'admin@etigo.com')->first();

        if (! $admin) {
            $this->command?->warn('Admin user not found. Run AdminSeeder first.');

            return;
        }

        foreach (City::with('vehicleClasses')->get() as $city) {
            $rates = self::RATES[NigerianCities::find($city->slug)['tier'] ?? 'B'];

            foreach ($city->vehicleClasses as $vehicleClass) {
                $classRates = $rates[$vehicleClass->name] ?? $rates['economy'];

                $exists = PricingConfig::where('city_id', $city->id)
                    ->where('vehicle_class_id', $vehicleClass->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                PricingConfig::create([
                    'city_id' => $city->id,
                    'vehicle_class_id' => $vehicleClass->id,
                    ...$classRates,
                    'free_waiting_minutes' => 5,
                    'version' => 1,
                    'effective_from' => ($city->launched_at ?? now())->copy()->min(now()),
                    'created_by_admin_id' => $admin->id,
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * NGN per fare tier and vehicle class.
     */
    private const RATES = [
        'A' => [
            'economy' => ['base_fare' => 700, 'per_km_rate' => 230, 'per_minute_rate' => 35, 'minimum_fare' => 1800, 'waiting_time_rate' => 40],
            'comfort' => ['base_fare' => 1000, 'per_km_rate' => 320, 'per_minute_rate' => 50, 'minimum_fare' => 2500, 'waiting_time_rate' => 60],
            'premium' => ['base_fare' => 1500, 'per_km_rate' => 450, 'per_minute_rate' => 70, 'minimum_fare' => 4000, 'waiting_time_rate' => 90],
            'suv' => ['base_fare' => 1300, 'per_km_rate' => 400, 'per_minute_rate' => 60, 'minimum_fare' => 3500, 'waiting_time_rate' => 80],
        ],
        'B' => [
            'economy' => ['base_fare' => 600, 'per_km_rate' => 200, 'per_minute_rate' => 30, 'minimum_fare' => 1500, 'waiting_time_rate' => 35],
            'comfort' => ['base_fare' => 850, 'per_km_rate' => 270, 'per_minute_rate' => 45, 'minimum_fare' => 2100, 'waiting_time_rate' => 50],
            'premium' => ['base_fare' => 1300, 'per_km_rate' => 380, 'per_minute_rate' => 60, 'minimum_fare' => 3400, 'waiting_time_rate' => 75],
            'suv' => ['base_fare' => 1100, 'per_km_rate' => 340, 'per_minute_rate' => 50, 'minimum_fare' => 3000, 'waiting_time_rate' => 70],
        ],
        'C' => [
            'economy' => ['base_fare' => 500, 'per_km_rate' => 170, 'per_minute_rate' => 25, 'minimum_fare' => 1200, 'waiting_time_rate' => 30],
            'comfort' => ['base_fare' => 700, 'per_km_rate' => 230, 'per_minute_rate' => 35, 'minimum_fare' => 1700, 'waiting_time_rate' => 40],
            'premium' => ['base_fare' => 1100, 'per_km_rate' => 320, 'per_minute_rate' => 50, 'minimum_fare' => 2800, 'waiting_time_rate' => 60],
            'suv' => ['base_fare' => 950, 'per_km_rate' => 290, 'per_minute_rate' => 45, 'minimum_fare' => 2500, 'waiting_time_rate' => 55],
        ],
    ];
}
