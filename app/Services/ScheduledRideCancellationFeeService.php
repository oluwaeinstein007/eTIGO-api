<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\AccountType;
use App\Enums\CancellationReason;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Jobs\ChargeScheduledRideCancellationFeeJob;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\UserPaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Late-cancellation fee for scheduled rides (Uber Reserve model): when the
 * passenger cancels a booking a driver has reserved, within the late window
 * before pickup, the fee goes to that driver in full (no commission, as in
 * the cancellation fee proposal). Emergency, safety, vehicle-mismatch and
 * driver-no-show cancellations are never charged. It is collected from the wallet or the
 * saved default card; cash and bank transfer bookings cannot be charged
 * after the fact, so the fee is waived for them. Off by default (fee 0).
 */
class ScheduledRideCancellationFeeService
{
    /**
     * Cancellation reasons that never carry a fee.
     */
    private const EXEMPT_REASONS = [
        CancellationReason::Emergency,
        CancellationReason::SafetyConcern,
        CancellationReason::VehicleMismatch,
        CancellationReason::DriverNoShow,
    ];

    public function __construct(
        private LedgerService $ledgerService,
        private PaymentGateway $paymentGateway,
    ) {}

    /**
     * The fee the passenger would pay by cancelling now (0 when none).
     */
    public static function feeIfCancelledNow(Ride $ride, ScheduledRide $scheduled): float
    {
        $settings = AppSetting::scheduledRides();
        $fee = $settings['late_cancellation_fee'];

        if ($fee <= 0
            || $scheduled->reserved_driver_id === null
            || $ride->status->isTerminal()
            || $ride->status === RideStatus::InProgress) {
            return 0.0;
        }

        // Once dispatched, only while the reserved driver is the one coming.
        if ($scheduled->dispatched_at !== null && $ride->driver_id !== $scheduled->reserved_driver_id) {
            return 0.0;
        }

        $windowStart = $scheduled->scheduled_pickup_at->copy()->subMinutes($settings['late_cancellation_window_minutes']);

        return now()->gte($windowStart) ? $fee : 0.0;
    }

    /**
     * Record the fee for a passenger cancellation and queue its collection.
     * Runs inside the cancellation transaction, before the reservation is
     * cleared.
     */
    public function assess(Ride $ride, ScheduledRide $scheduled, User $cancelledBy): void
    {
        if ($cancelledBy->id !== $ride->passenger_id) {
            return;
        }

        $fee = self::feeIfCancelledNow($ride, $scheduled);
        $reason = CancellationReason::tryFrom((string) $ride->cancellation_reason);

        if ($fee <= 0 || in_array($reason, self::EXEMPT_REASONS, true)) {
            return;
        }

        $chargeable = in_array($ride->payment_method, [PaymentMethod::Wallet, PaymentMethod::Card], true);

        $scheduled->update([
            'late_cancellation_fee' => $fee,
            'late_cancellation_fee_status' => $chargeable ? 'pending' : 'waived',
            'late_cancellation_fee_note' => $chargeable ? null : 'Cash and bank transfer bookings cannot be charged after cancellation.',
        ]);

        AuditLog::record($ride, 'scheduled_ride_late_cancellation_fee', $cancelledBy, null, [
            'fee' => $fee,
            'status' => $chargeable ? 'pending' : 'waived',
            'driver_user_id' => $scheduled->reserved_driver_id,
        ]);

        if ($chargeable) {
            ChargeScheduledRideCancellationFeeJob::dispatch($scheduled->id)->afterCommit();
        }
    }

    /**
     * Collect a pending fee. Safe to run twice: only a pending fee is
     * charged and the ledger journal is idempotent.
     */
    public function charge(string $scheduledRideId): void
    {
        $scheduled = ScheduledRide::with('ride.passenger')->find($scheduledRideId);

        if ($scheduled === null || $scheduled->late_cancellation_fee_status !== 'pending') {
            return;
        }

        $ride = $scheduled->ride;
        $feeKobo = (int) round((float) $scheduled->late_cancellation_fee * 100);
        $driver = Driver::where('user_id', $scheduled->reserved_driver_id)->first();

        if ($driver === null || $feeKobo <= 0) {
            $this->finish($scheduled, 'waived', 'No driver to compensate.');

            return;
        }

        try {
            if ($ride->payment_method === PaymentMethod::Wallet) {
                DB::transaction(function () use ($ride, $scheduled, $driver, $feeKobo) {
                    $wallet = $this->lockedWallet($ride->passenger, $feeKobo);
                    $this->post($wallet, $ride, $scheduled, $driver, $feeKobo);
                });
            } else {
                $reference = $this->chargeCard($ride, $scheduled, $feeKobo);
                $scheduled->late_cancellation_fee_note = "Card charge {$reference}";
                $this->post($this->ledgerService->systemAccount(AccountType::PspClearing), $ride, $scheduled, $driver, $feeKobo);
            }
        } catch (\DomainException $e) {
            $this->finish($scheduled, 'failed', $e->getMessage());

            return;
        }

        $this->finish($scheduled, 'charged', null);
    }

    /**
     * Payer → driver, in full.
     */
    private function post(Account $payer, Ride $ride, ScheduledRide $scheduled, Driver $driver, int $feeKobo): void
    {
        $driverAccount = $this->ledgerService->findOrCreateAccount(
            $driver->getMorphClass(),
            $driver->id,
            AppSetting::wallet('settlement_delay') === 'instant'
                ? AccountType::DriverEarningsAvailable
                : AccountType::DriverEarningsPending,
        );

        $this->ledgerService->postJournal([
            ['account_id' => $payer->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $feeKobo],
            ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $feeKobo],
        ], [
            'description' => "Scheduled ride late-cancellation fee for ride {$ride->id}",
            'idempotency_key' => "scheduled-cancel-fee-{$scheduled->id}",
            'metadata' => [
                'ride_id' => $ride->id,
                'fee_kobo' => $feeKobo,
                'driver_id' => $driver->id,
                'payment_method' => $ride->payment_method->value,
                'category' => 'scheduled_late_cancellation_fee',
            ],
        ]);
    }

    /**
     * @throws \DomainException when the wallet cannot pay
     */
    private function lockedWallet(User $passenger, int $feeKobo): Account
    {
        $account = $this->ledgerService->findOrCreateAccount($passenger->getMorphClass(), $passenger->id, AccountType::PassengerWallet);
        $account = Account::whereKey($account->id)->lockForUpdate()->firstOrFail();

        if (! $account->isActive()) {
            throw new \DomainException('Wallet is frozen or closed.');
        }

        if ($account->availableBalance() < $feeKobo) {
            throw new \DomainException('Insufficient wallet balance for the cancellation fee.');
        }

        return $account;
    }

    /**
     * @return string The gateway transaction id
     *
     * @throws \DomainException when the card charge does not succeed
     */
    private function chargeCard(Ride $ride, ScheduledRide $scheduled, int $feeKobo): string
    {
        $card = UserPaymentMethod::where('user_id', $ride->passenger_id)->where('is_default', true)->first();

        if ($card === null) {
            throw new \DomainException('No saved card to charge the cancellation fee to.');
        }

        // Claim the charge first so a retried job can never charge twice.
        $claimed = ScheduledRide::whereKey($scheduled->id)
            ->where('late_cancellation_fee_status', 'pending')
            ->update(['late_cancellation_fee_status' => 'charging']);

        if ($claimed === 0) {
            throw new \DomainException('The cancellation fee is already being charged.');
        }

        try {
            $result = $this->paymentGateway->chargeWithToken([
                'token' => $card->gateway_token,
                'provider' => $card->provider,
                'email' => $card->gateway_email ?? $ride->passenger->paymentEmail(),
                'amount' => $feeKobo / 100,
                'currency' => $ride->fare_currency ?? 'NGN',
                'tx_ref' => 'ETIGO-SCHFEE-'.Str::upper(Str::random(12)),
            ]);
        } catch (\Throwable $e) {
            // The charge may have gone through (timeout); finance checks it.
            Log::error('Scheduled ride cancellation fee card charge errored', ['scheduled_ride_id' => $scheduled->id, 'error' => $e->getMessage()]);

            throw new \DomainException('Card charge error; check the payment provider before charging again.');
        }

        if (($result['status'] ?? null) !== 'successful') {
            throw new \DomainException("Card charge status: {$result['status']}.");
        }

        return (string) $result['transaction_id'];
    }

    private function finish(ScheduledRide $scheduled, string $status, ?string $note): void
    {
        DB::table('scheduled_rides')->where('id', $scheduled->id)->update([
            'late_cancellation_fee_status' => $status,
            'late_cancellation_fee_note' => $note ?? $scheduled->late_cancellation_fee_note,
            'updated_at' => now(),
        ]);

        if ($status === 'failed') {
            Log::warning('Scheduled ride cancellation fee not collected', ['scheduled_ride_id' => $scheduled->id, 'reason' => $note]);
        }
    }
}
