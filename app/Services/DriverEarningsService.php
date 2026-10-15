<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Driver;
use App\Models\Ride;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DriverEarningsService
{
    public function __construct(
        private LedgerService $ledgerService,
        private CommissionService $commissionService,
    ) {}

    /**
     * Credit ride earnings to driver (for card/cash rides, not wallet).
     * Wallet rides are handled by WalletPaymentService::settle().
     */
    public function creditRideEarnings(Ride $ride, int $fareAmountKobo): void
    {
        $driver = Driver::where('user_id', $ride->driver_id)->firstOrFail();

        $commission = $this->commissionService->calculate($fareAmountKobo, $driver->id);

        $platformAccount = $this->ledgerService->systemAccount(AccountType::PlatformCommission);
        $pspClearingAccount = $this->ledgerService->systemAccount(AccountType::PspClearing);

        if ($ride->payment_method->value === 'cash') {
            $driverAccount = $this->ledgerService->findOrCreateAccount(
                'App\\Models\\Driver',
                $driver->id,
                AccountType::DriverEarningsAvailable,
            );

            if ($commission['commission'] > 0) {
                $maxNegative = AppSetting::wallet('max_negative_balance');
                $projectedBalance = $driverAccount->balance - $commission['commission'];

                if ($projectedBalance < $maxNegative) {
                    Log::warning('Cash commission would exceed negative balance threshold', [
                        'driver_id' => $driver->id,
                        'ride_id' => $ride->id,
                        'current_balance' => $driverAccount->balance,
                        'commission' => $commission['commission'],
                        'projected' => $projectedBalance,
                        'threshold' => $maxNegative,
                    ]);
                }

                $this->ledgerService->postJournal([
                    ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $commission['commission']],
                    ['account_id' => $platformAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $commission['commission']],
                ], [
                    'description' => "Cash ride commission for ride {$ride->id}",
                    'idempotency_key' => "cash-commission-{$ride->id}",
                    'metadata' => [
                        'ride_id' => $ride->id,
                        'fare_kobo' => $fareAmountKobo,
                        'commission_rate' => $commission['rate'],
                        'commission_kobo' => $commission['commission'],
                    ],
                ]);
            }
        } else {
            $earningsAccountType = AppSetting::wallet('settlement_delay') === 'instant'
                ? AccountType::DriverEarningsAvailable
                : AccountType::DriverEarningsPending;

            $driverAccount = $this->ledgerService->findOrCreateAccount(
                'App\\Models\\Driver',
                $driver->id,
                $earningsAccountType,
            );
            $journalLines = array_values(array_filter([
                ['account_id' => $pspClearingAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $fareAmountKobo],
                $commission['commission'] > 0
                    ? ['account_id' => $platformAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $commission['commission']]
                    : null,
                $commission['net_earnings'] > 0
                    ? ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $commission['net_earnings']]
                    : null,
            ]));

            $this->ledgerService->postJournal($journalLines, [
                'description' => "Card ride settlement for ride {$ride->id}",
                'idempotency_key' => "card-settlement-{$ride->id}",
                'metadata' => [
                    'ride_id' => $ride->id,
                    'fare_kobo' => $fareAmountKobo,
                    'commission_rate' => $commission['rate'],
                    'commission_kobo' => $commission['commission'],
                    'net_earnings_kobo' => $commission['net_earnings'],
                ],
            ]);
        }
    }

    /**
     * Credit a card tip to the driver in full (tips carry no commission).
     * Cash tips are handed over directly and never touch the ledger.
     */
    public function creditTip(Ride $ride, int $tipAmountKobo): void
    {
        if ($tipAmountKobo <= 0) {
            return;
        }

        $driver = Driver::where('user_id', $ride->driver_id)->firstOrFail();

        $driverAccount = $this->ledgerService->findOrCreateAccount(
            'App\\Models\\Driver',
            $driver->id,
            AppSetting::wallet('settlement_delay') === 'instant'
                ? AccountType::DriverEarningsAvailable
                : AccountType::DriverEarningsPending,
        );
        $pspClearingAccount = $this->ledgerService->systemAccount(AccountType::PspClearing);

        $this->ledgerService->postJournal([
            ['account_id' => $pspClearingAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $tipAmountKobo],
            ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $tipAmountKobo],
        ], [
            'description' => "Card tip for ride {$ride->id}",
            'idempotency_key' => "tip-{$ride->id}",
            'metadata' => [
                'ride_id' => $ride->id,
                'tip_kobo' => $tipAmountKobo,
            ],
        ]);
    }

    /**
     * Get earnings summary for a driver.
     *
     * @return array{pending: int, available: int, total_paid: int}
     */
    public function getSummary(string $driverId): array
    {
        $availableAccount = Account::where('owner_type', 'App\\Models\\Driver')
            ->where('owner_id', $driverId)
            ->where('type', AccountType::DriverEarningsAvailable)
            ->first();

        $pendingAccount = Account::where('owner_type', 'App\\Models\\Driver')
            ->where('owner_id', $driverId)
            ->where('type', AccountType::DriverEarningsPending)
            ->first();

        $totalPaid = DB::table('payouts')
            ->where('driver_id', $driverId)
            ->where('status', 'paid')
            ->sum('amount');

        return [
            'pending' => $pendingAccount?->balance ?? 0,
            'available' => $availableAccount?->balance ?? 0,
            'total_paid' => (int) $totalPaid,
        ];
    }

    public function hasExcessiveNegativeBalance(string $driverId): bool
    {
        $account = Account::where('owner_type', 'App\\Models\\Driver')
            ->where('owner_id', $driverId)
            ->where('type', AccountType::DriverEarningsAvailable)
            ->first();

        if (! $account) {
            return false;
        }

        return $account->balance < AppSetting::wallet('max_negative_balance');
    }
}
