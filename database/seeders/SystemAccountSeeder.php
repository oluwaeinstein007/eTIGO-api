<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Services\LedgerService;
use Illuminate\Database\Seeder;

class SystemAccountSeeder extends Seeder
{
    public function run(LedgerService $ledgerService): void
    {
        $systemAccounts = [
            AccountType::PlatformCommission,
            AccountType::PspClearing,
            AccountType::Refunds,
            AccountType::PlatformFees,
        ];

        foreach ($systemAccounts as $type) {
            $ledgerService->systemAccount($type);

            $this->command?->info("System account [{$type->value}] ensured.");
        }
    }
}
