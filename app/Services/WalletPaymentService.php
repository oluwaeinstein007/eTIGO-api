<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\HoldStatus;
use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\Driver;
use App\Models\Hold;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WalletPaymentService
{
    public function __construct(
        private LedgerService $ledgerService,
        private CommissionService $commissionService,
    ) {}

    /**
     * Place a hold on the passenger wallet for the estimated fare.
     */
    public function placeHold(User $passenger, Ride $ride, int $estimateAmountKobo): Hold
    {
        return DB::transaction(function () use ($passenger, $ride, $estimateAmountKobo) {
            $account = $this->ledgerService->findOrCreateAccount(
                $passenger->getMorphClass(),
                $passenger->id,
                AccountType::PassengerWallet,
            );

            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            if (! $account->isActive()) {
                throw new \DomainException('Wallet is frozen or closed.');
            }

            $available = $account->availableBalance();
            if ($available < $estimateAmountKobo) {
                throw new \DomainException('Insufficient wallet balance.');
            }

            return Hold::create([
                'account_id' => $account->id,
                'ride_id' => $ride->id,
                'amount' => $estimateAmountKobo,
                'status' => HoldStatus::Active,
                'expires_at' => now()->addHours(config('wallet.hold_expiry_hours', 4)),
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Settle a ride: capture the hold, post journal entries for fare split.
     */
    public function settle(Ride $ride, int $finalFareKobo): void
    {
        DB::transaction(function () use ($ride, $finalFareKobo) {
            $hold = Hold::where('ride_id', $ride->id)
                ->where('status', HoldStatus::Active)
                ->lockForUpdate()
                ->first();

            if (! $hold) {
                throw new \DomainException('No active hold found for this ride.');
            }

            $passengerAccount = Account::whereKey($hold->account_id)->lockForUpdate()->first();

            $driver = Driver::where('user_id', $ride->driver_id)->firstOrFail();

            $commission = $this->commissionService->calculate($finalFareKobo, $driver->id);

            $platformAccount = $this->ledgerService->systemAccount(AccountType::PlatformCommission);
            $driverAccount = $this->ledgerService->findOrCreateAccount(
                'App\\Models\\Driver',
                $driver->id,
                AccountType::DriverEarningsAvailable,
            );

            $this->ledgerService->postJournal([
                ['account_id' => $passengerAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $finalFareKobo],
                ['account_id' => $platformAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $commission['commission']],
                ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $commission['net_earnings']],
            ], [
                'description' => "Ride settlement for ride {$ride->id}",
                'idempotency_key' => "ride-settlement-{$ride->id}",
                'metadata' => [
                    'ride_id' => $ride->id,
                    'fare_kobo' => $finalFareKobo,
                    'commission_rate' => $commission['rate'],
                    'commission_kobo' => $commission['commission'],
                    'net_earnings_kobo' => $commission['net_earnings'],
                ],
            ]);

            // Capture the hold
            $hold->update([
                'status' => HoldStatus::Captured,
                'captured_at' => now(),
            ]);

            // If hold was more than final fare, the surplus is already released
            // because the debit was for finalFareKobo, not hold amount
        });
    }

    /**
     * Release a hold (on cancellation or no-driver-found).
     */
    public function releaseHold(Ride $ride): void
    {
        DB::transaction(function () use ($ride) {
            $hold = Hold::where('ride_id', $ride->id)
                ->where('status', HoldStatus::Active)
                ->lockForUpdate()
                ->first();

            if (! $hold) {
                return;
            }

            $hold->update([
                'status' => HoldStatus::Released,
                'released_at' => now(),
            ]);
        });
    }
}
