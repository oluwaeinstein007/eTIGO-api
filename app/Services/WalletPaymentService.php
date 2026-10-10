<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\HoldStatus;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\Driver;
use App\Models\Hold;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\WalletRidePaymentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     *
     * When the final fare exceeds the passenger's available balance, charges
     * whatever balance exists and flags the ride for admin review with the
     * shortfall amount.
     *
     * @return array{settled: bool, amount_charged: int, shortfall: int}
     */
    public function settle(Ride $ride, int $finalFareKobo): array
    {
        $result = DB::transaction(function () use ($ride, $finalFareKobo) {
            $hold = Hold::where('ride_id', $ride->id)
                ->where('status', HoldStatus::Active)
                ->lockForUpdate()
                ->first();

            if (! $hold) {
                throw new \DomainException('No active hold found for this ride.');
            }

            $passengerAccount = Account::whereKey($hold->account_id)->lockForUpdate()->first();

            $otherHoldsTotal = (int) Hold::where('account_id', $passengerAccount->id)
                ->where('status', HoldStatus::Active)
                ->where('id', '!=', $hold->id)
                ->sum('amount');
            $spendableBalance = max(0, $passengerAccount->balance - $otherHoldsTotal);

            $chargeAmount = $finalFareKobo;
            $shortfall = 0;

            if ($spendableBalance < $finalFareKobo) {
                $chargeAmount = $spendableBalance;
                $shortfall = $finalFareKobo - $chargeAmount;
            }

            if ($chargeAmount > 0) {
                $driver = Driver::where('user_id', $ride->driver_id)->firstOrFail();
                $commission = $this->commissionService->calculate($chargeAmount, $driver->id);

                $platformAccount = $this->ledgerService->systemAccount(AccountType::PlatformCommission);
                $earningsAccountType = config('wallet.settlement_delay', 'instant') === 'instant'
                    ? AccountType::DriverEarningsAvailable
                    : AccountType::DriverEarningsPending;
                $driverAccount = $this->ledgerService->findOrCreateAccount(
                    Driver::class,
                    $driver->id,
                    $earningsAccountType,
                );

                $journalLines = array_filter([
                    ['account_id' => $passengerAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $chargeAmount],
                    $commission['commission'] > 0
                        ? ['account_id' => $platformAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $commission['commission']]
                        : null,
                    $commission['net_earnings'] > 0
                        ? ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $commission['net_earnings']]
                        : null,
                ]);

                $this->ledgerService->postJournal(array_values($journalLines), [
                    'description' => "Ride settlement for ride {$ride->id}",
                    'idempotency_key' => "ride-settlement-{$ride->id}",
                    'metadata' => [
                        'ride_id' => $ride->id,
                        'fare_kobo' => $finalFareKobo,
                        'charged_kobo' => $chargeAmount,
                        'shortfall_kobo' => $shortfall,
                        'commission_rate' => $commission['rate'],
                        'commission_kobo' => $commission['commission'],
                        'net_earnings_kobo' => $commission['net_earnings'],
                    ],
                ]);
            }

            $hold->update([
                'status' => HoldStatus::Captured,
                'captured_at' => now(),
            ]);

            if ($shortfall > 0) {
                $ride->update([
                    'payment_status' => PaymentStatus::Failed,
                    'pricing_snapshot' => array_merge(
                        is_array($ride->pricing_snapshot) ? $ride->pricing_snapshot : [],
                        ['wallet_shortfall_kobo' => $shortfall],
                    ),
                ]);

                Log::warning('Wallet settlement shortfall — flagged for admin review', [
                    'ride_id' => $ride->id,
                    'final_fare_kobo' => $finalFareKobo,
                    'charged_kobo' => $chargeAmount,
                    'shortfall_kobo' => $shortfall,
                ]);
            }

            return [
                'settled' => $shortfall === 0,
                'amount_charged' => $chargeAmount,
                'shortfall' => $shortfall,
            ];
        });

        if ($result['amount_charged'] > 0) {
            rescue(fn () => User::find($ride->passenger_id)
                ?->notify(new WalletRidePaymentNotification($ride->id, $result['amount_charged'])));
        }

        return $result;
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
