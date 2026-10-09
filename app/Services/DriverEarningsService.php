<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\Driver;
use App\Models\Ride;
use Illuminate\Support\Facades\DB;

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

        $driverAccount = $this->ledgerService->findOrCreateAccount(
            'App\\Models\\Driver',
            $driver->id,
            AccountType::DriverEarningsAvailable,
        );

        if ($ride->payment_method->value === 'cash') {
            // Cash ride: driver already has the money, debit commission from earnings
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
        } else {
            // Card ride: credit net earnings, commission to platform
            $this->ledgerService->postJournal([
                ['account_id' => $pspClearingAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $fareAmountKobo],
                ['account_id' => $platformAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $commission['commission']],
                ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $commission['net_earnings']],
            ], [
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

        $totalPaid = DB::table('payouts')
            ->where('driver_id', $driverId)
            ->where('status', 'paid')
            ->sum('amount');

        return [
            'available' => $availableAccount?->balance ?? 0,
            'total_paid' => (int) $totalPaid,
        ];
    }
}
