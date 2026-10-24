<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Everything a local or staging database needs, in dependency order. The
 * demo seeders refuse to run in production; ReferenceDataSeeder (run on every
 * deploy) is the only part production gets.
 *
 * Model events stay on: they give rides and drivers their reference numbers,
 * keep a city's is_active in step with its launch status and give each new
 * vehicle its maintenance items.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            AdminSeeder::class,
            CitySeeder::class,
            PricingConfigSeeder::class,
            DemoSeeder::class,
            CityActivityDemoSeeder::class,
            ScheduledRidesDemoSeeder::class,
            DisputesDemoSeeder::class,
            LostAndFoundDemoSeeder::class,
            DriverOperationsDemoSeeder::class,
            FinanceDemoSeeder::class,
        ]);
    }
}
