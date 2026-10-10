<?php

namespace Database\Seeders;

use App\Models\CommissionConfig;
use Illuminate\Database\Seeder;

class CommissionConfigSeeder extends Seeder
{
    public function run(): void
    {
        CommissionConfig::firstOrCreate(
            [
                'driver_id' => null,
                'is_active' => true,
            ],
            [
                'rate' => config('wallet.default_commission_rate', 0.2000),
            ],
        );

        $this->command->info('Global commission config (20%) ensured.');
    }
}
