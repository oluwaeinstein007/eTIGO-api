<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\PricingConfig;
use App\Models\User;
use Illuminate\Database\Seeder;

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

        $defaults = $this->defaults();

        foreach (City::all() as $city) {
            $cityKey = mb_strtolower($city->name);
            $cityDefaults = $defaults[$cityKey] ?? $defaults['default'];

            foreach ($city->vehicleClasses as $vehicleClass) {
                $classKey = mb_strtolower($vehicleClass->name);
                $rates = $cityDefaults[$classKey] ?? $cityDefaults['economy'] ?? array_values($cityDefaults)[0];

                $exists = PricingConfig::where('city_id', $city->id)
                    ->where('vehicle_class_id', $vehicleClass->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                PricingConfig::create([
                    'city_id' => $city->id,
                    'vehicle_class_id' => $vehicleClass->id,
                    'base_fare' => $rates['base_fare'],
                    'per_km_rate' => $rates['per_km_rate'],
                    'per_minute_rate' => $rates['per_minute_rate'],
                    'minimum_fare' => $rates['minimum_fare'],
                    'waiting_time_rate' => $rates['waiting_time_rate'],
                    'free_waiting_minutes' => $rates['free_waiting_minutes'],
                    'version' => 1,
                    'effective_from' => now(),
                    'created_by_admin_id' => $admin->id,
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * @return array<string, array<string, array<string, float|int>>>
     */
    private function defaults(): array
    {
        return [
            'abuja' => [
                'economy' => [
                    'base_fare' => 600,
                    'per_km_rate' => 250,
                    'per_minute_rate' => 40,
                    'minimum_fare' => 1500,
                    'waiting_time_rate' => 50,
                    'free_waiting_minutes' => 5,
                ],
                'comfort' => [
                    'base_fare' => 800,
                    'per_km_rate' => 350,
                    'per_minute_rate' => 55,
                    'minimum_fare' => 2000,
                    'waiting_time_rate' => 65,
                    'free_waiting_minutes' => 5,
                ],
                'premium' => [
                    'base_fare' => 1200,
                    'per_km_rate' => 500,
                    'per_minute_rate' => 80,
                    'minimum_fare' => 3000,
                    'waiting_time_rate' => 100,
                    'free_waiting_minutes' => 5,
                ],
                'suv' => [
                    'base_fare' => 1000,
                    'per_km_rate' => 400,
                    'per_minute_rate' => 65,
                    'minimum_fare' => 2500,
                    'waiting_time_rate' => 80,
                    'free_waiting_minutes' => 5,
                ],
            ],
            'lagos' => [
                'economy' => [
                    'base_fare' => 600,
                    'per_km_rate' => 250,
                    'per_minute_rate' => 40,
                    'minimum_fare' => 1500,
                    'waiting_time_rate' => 50,
                    'free_waiting_minutes' => 5,
                ],
                'comfort' => [
                    'base_fare' => 800,
                    'per_km_rate' => 350,
                    'per_minute_rate' => 55,
                    'minimum_fare' => 2000,
                    'waiting_time_rate' => 65,
                    'free_waiting_minutes' => 5,
                ],
                'premium' => [
                    'base_fare' => 1200,
                    'per_km_rate' => 500,
                    'per_minute_rate' => 80,
                    'minimum_fare' => 3000,
                    'waiting_time_rate' => 100,
                    'free_waiting_minutes' => 5,
                ],
                'suv' => [
                    'base_fare' => 1000,
                    'per_km_rate' => 400,
                    'per_minute_rate' => 65,
                    'minimum_fare' => 2500,
                    'waiting_time_rate' => 80,
                    'free_waiting_minutes' => 5,
                ],
            ],
            'port harcourt' => [
                'economy' => [
                    'base_fare' => 500,
                    'per_km_rate' => 200,
                    'per_minute_rate' => 35,
                    'minimum_fare' => 1200,
                    'waiting_time_rate' => 40,
                    'free_waiting_minutes' => 5,
                ],
                'comfort' => [
                    'base_fare' => 700,
                    'per_km_rate' => 300,
                    'per_minute_rate' => 50,
                    'minimum_fare' => 1800,
                    'waiting_time_rate' => 55,
                    'free_waiting_minutes' => 5,
                ],
                'premium' => [
                    'base_fare' => 1000,
                    'per_km_rate' => 450,
                    'per_minute_rate' => 70,
                    'minimum_fare' => 2500,
                    'waiting_time_rate' => 85,
                    'free_waiting_minutes' => 5,
                ],
                'suv' => [
                    'base_fare' => 850,
                    'per_km_rate' => 350,
                    'per_minute_rate' => 55,
                    'minimum_fare' => 2000,
                    'waiting_time_rate' => 70,
                    'free_waiting_minutes' => 5,
                ],
            ],
            'default' => [
                'economy' => [
                    'base_fare' => 600,
                    'per_km_rate' => 250,
                    'per_minute_rate' => 40,
                    'minimum_fare' => 1500,
                    'waiting_time_rate' => 50,
                    'free_waiting_minutes' => 5,
                ],
                'comfort' => [
                    'base_fare' => 800,
                    'per_km_rate' => 350,
                    'per_minute_rate' => 55,
                    'minimum_fare' => 2000,
                    'waiting_time_rate' => 65,
                    'free_waiting_minutes' => 5,
                ],
                'premium' => [
                    'base_fare' => 1200,
                    'per_km_rate' => 500,
                    'per_minute_rate' => 80,
                    'minimum_fare' => 3000,
                    'waiting_time_rate' => 100,
                    'free_waiting_minutes' => 5,
                ],
                'suv' => [
                    'base_fare' => 1000,
                    'per_km_rate' => 400,
                    'per_minute_rate' => 65,
                    'minimum_fare' => 2500,
                    'waiting_time_rate' => 80,
                    'free_waiting_minutes' => 5,
                ],
            ],
        ];
    }
}
