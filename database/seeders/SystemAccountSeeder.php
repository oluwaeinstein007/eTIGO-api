<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Seeder;

class SystemAccountSeeder extends Seeder
{
    public function run(): void
    {
        $systemAccounts = [
            AccountType::PlatformCommission,
            AccountType::PspClearing,
            AccountType::Refunds,
        ];

        foreach ($systemAccounts as $type) {
            Account::firstOrCreate(
                [
                    'owner_type' => 'system',
                    'owner_id' => '00000000-0000-0000-0000-000000000000',
                    'type' => $type,
                ],
                [
                    'currency' => 'NGN',
                    'status' => AccountStatus::Active,
                    'balance' => 0,
                    'balance_version' => 0,
                ],
            );

            $this->command->info("System account [{$type->value}] ensured.");
        }
    }
}
