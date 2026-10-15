<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data every environment needs, including production: ledger
 * system accounts, the default commission rate, gamification tiers and point
 * multipliers. Every seeder here only creates missing rows, so it is safe to
 * run on each deploy and never overwrites values changed by admins.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SystemAccountSeeder::class,
            CommissionConfigSeeder::class,
            TierConfigSeeder::class,
            PointMultiplierConfigSeeder::class,
        ]);
    }
}
