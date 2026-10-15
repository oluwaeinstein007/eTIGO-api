<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Driver;
use App\Models\EvReservation;
use App\Models\Journal;
use App\Models\User;

/**
 * Collects the EV stall reservation fee from the driver's available earnings
 * when a stall is actually held for them (not while they wait in a queue).
 * The fee pays for holding the stall, so it is kept on a no-show or a late
 * cancellation and refunded only for a quick change of mind. Like cash-ride
 * commission, it may take the balance below zero down to the wallet's
 * max_negative_balance setting.
 */
class EvReservationFeeService
{
    public function __construct(private LedgerService $ledgerService) {}

    public function feeKobo(EvReservation $reservation): int
    {
        return $reservation->fee_waived ? 0 : (int) round((float) $reservation->fee_amount * 100);
    }

    /**
     * @throws \RuntimeException when the driver's earnings cannot cover the fee
     */
    public function assertCanPay(User $driverUser, int $feeKobo): void
    {
        if ($feeKobo > 0 && ! $this->canCover($this->earningsAccount($driverUser), $feeKobo)) {
            throw new \RuntimeException($this->cannotCoverMessage($feeKobo));
        }
    }

    /**
     * @throws \RuntimeException when the driver's earnings cannot cover the fee
     */
    public function charge(EvReservation $reservation): void
    {
        $feeKobo = $this->feeKobo($reservation);

        if ($feeKobo <= 0 || $reservation->fee_charged_at !== null) {
            return;
        }

        $account = $this->earningsAccount(User::findOrFail($reservation->driver_id));
        $account = Account::whereKey($account->id)->lockForUpdate()->firstOrFail();

        if (! $this->canCover($account, $feeKobo)) {
            throw new \RuntimeException($this->cannotCoverMessage($feeKobo));
        }

        $this->ledgerService->postJournal([
            ['account_id' => $account->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $feeKobo],
            ['account_id' => $this->ledgerService->systemAccount(AccountType::PlatformFees)->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $feeKobo],
        ], [
            'description' => "EV stall reservation fee for reservation {$reservation->id}",
            'idempotency_key' => $this->feeKey($reservation),
            'metadata' => [
                'reservation_id' => $reservation->id,
                'station_id' => $reservation->station_id,
                'fee_kobo' => $feeKobo,
                'category' => 'ev_reservation_fee',
            ],
        ]);

        $reservation->update(['fee_charged_at' => now()]);
    }

    /**
     * Refund the fee when the driver cancels within the free-cancellation
     * window after the stall was assigned.
     *
     * @return bool Whether the fee was refunded
     */
    public function refundIfWithinGracePeriod(EvReservation $reservation): bool
    {
        if ($reservation->fee_charged_at === null || $reservation->fee_refunded_at !== null) {
            return false;
        }

        $graceMinutes = (int) config('ev_charging.free_cancellation_minutes', 2);

        if ($reservation->fee_charged_at->lt(now()->subMinutes($graceMinutes))) {
            return false;
        }

        $journal = Journal::where('idempotency_key', $this->feeKey($reservation))->first();

        if (! $journal) {
            return false;
        }

        $this->ledgerService->reverse($journal, "EV stall reservation fee refund for reservation {$reservation->id}");
        $reservation->update(['fee_refunded_at' => now()]);

        return true;
    }

    private function canCover(Account $account, int $feeKobo): bool
    {
        return $account->availableBalance() - $feeKobo >= (int) AppSetting::wallet('max_negative_balance');
    }

    private function cannotCoverMessage(int $feeKobo): string
    {
        return 'Your earnings balance cannot cover the ₦'.number_format($feeKobo / 100, 2).' reservation fee.';
    }

    private function earningsAccount(User $driverUser): Account
    {
        $driver = Driver::where('user_id', $driverUser->id)->firstOrFail();

        return $this->ledgerService->findOrCreateAccount('App\\Models\\Driver', $driver->id, AccountType::DriverEarningsAvailable);
    }

    private function feeKey(EvReservation $reservation): string
    {
        return "ev-fee-{$reservation->id}";
    }
}
