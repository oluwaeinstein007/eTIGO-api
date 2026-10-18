<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\AccountType;
use App\Enums\BalanceDueReason;
use App\Enums\BalanceDueStatus;
use App\Enums\CancellationReason;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Jobs\ChargeScheduledRideCancellationFeeJob;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Journal;
use App\Models\PassengerBalanceDue;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Models\VehicleClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Late-cancellation fee for scheduled rides (Uber Reserve model), as the
 * client decided on 16 Oct 2026: when the passenger cancels a booking a
 * driver has reserved, within 30 minutes of the ride time, they pay the
 * economy base fare of the ride's city, and the reserved driver receives it
 * in full (no commission, as in the cancellation fee proposal). Emergency,
 * safety, vehicle-mismatch and driver-no-show cancellations are never
 * charged. Wallet and card bookings are charged at once; whatever cannot be
 * collected that way, including every cash or bank-transfer booking,
 * becomes a balance due (PassengerBalanceService).
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

    /**
     * Fee statuses a passenger can still contest in a dispute: collected,
     * being collected, owed as a balance due, or a card charge finance must
     * check. A waived fee has nothing left to contest.
     */
    private const DISPUTABLE_STATUSES = ['pending', 'charging', 'charged', 'balance_due', 'failed'];

    public function __construct(
        private LedgerService $ledgerService,
        private PaymentGateway $paymentGateway,
        private PassengerBalanceService $balanceService,
        private WalletRefundService $refunds,
    ) {}

    /**
     * The late-cancellation fee for this ride, in NGN (0 when switched off):
     * by default the current base fare of the economy class in the ride's
     * city, falling back to the base fare the ride was quoted with when the
     * city has no economy pricing.
     */
    public static function feeAmount(Ride $ride, ?array $settings = null): float
    {
        $settings ??= AppSetting::scheduledRides();

        return match ($settings['late_cancellation_fee_basis']) {
            'flat' => max(0.0, $settings['late_cancellation_fee']),
            'economy_base_fare' => self::economyBaseFare($ride, $settings['late_cancellation_fee_vehicle_class']),
            default => 0.0,
        };
    }

    private static function economyBaseFare(Ride $ride, string $className): float
    {
        $classId = VehicleClass::where('name', $className)->value('id');
        $pricing = $classId ? PricingConfig::currentFor($ride->city_id, $classId) : null;

        return round((float) ($pricing?->base_fare ?? data_get($ride->pricing_snapshot, 'base_fare', 0)), 2);
    }

    /**
     * The fee the passenger would pay by cancelling now (0 when none).
     */
    public static function feeIfCancelledNow(Ride $ride, ScheduledRide $scheduled): float
    {
        $settings = AppSetting::scheduledRides();

        if ($settings['late_cancellation_fee_basis'] === 'off'
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

        return now()->gte($windowStart) ? self::feeAmount($ride, $settings) : 0.0;
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
            'late_cancellation_fee_status' => $chargeable ? 'pending' : 'balance_due',
            'late_cancellation_fee_note' => $chargeable ? null : 'Cash and bank transfer bookings cannot be charged after cancellation; recorded as a balance due.',
        ]);

        AuditLog::record($ride, 'scheduled_ride_late_cancellation_fee', $cancelledBy, null, [
            'fee' => $fee,
            'status' => $chargeable ? 'pending' : 'balance_due',
            'driver_user_id' => $scheduled->reserved_driver_id,
        ]);

        if ($chargeable) {
            ChargeScheduledRideCancellationFeeJob::dispatch($scheduled->id)->afterCommit();

            return;
        }

        $this->recordBalanceDue($ride, $scheduled, (int) round($fee * 100));
    }

    private function recordBalanceDue(Ride $ride, ScheduledRide $scheduled, int $feeKobo): void
    {
        $this->balanceService->record(
            $ride->passenger_id,
            $ride,
            BalanceDueReason::LateCancellationFee,
            $feeKobo,
            $scheduled->reserved_driver_id,
        );
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

        // Paid from the wallet or card, or owed as a balance due; a card
        // charge whose outcome is unknown is left for finance to check.

        try {
            if ($ride->payment_method === PaymentMethod::Wallet) {
                DB::transaction(function () use ($ride, $scheduled, $driver, $feeKobo) {
                    $wallet = $this->lockedWallet($ride->passenger, $feeKobo);
                    $this->post($wallet, $ride, $scheduled, $driver, $feeKobo);
                });
            } else {
                $reference = $this->chargeCard($ride, $scheduled, $feeKobo);

                if ($reference === null) {
                    // Another worker is charging it; leave the status to them.
                    return;
                }

                $scheduled->late_cancellation_fee_note = "Card charge {$reference}";

                try {
                    $this->post($this->ledgerService->systemAccount(AccountType::PspClearing), $ride, $scheduled, $driver, $feeKobo);
                } catch (\Throwable $e) {
                    // The card was charged: never turn this into a balance due.
                    throw new \RuntimeException("Card charged ({$reference}) but the ledger posting failed: {$e->getMessage()}");
                }
            }
        } catch (\DomainException $e) {
            // Status and balance together, so a crash can never lose the debt.
            DB::transaction(function () use ($ride, $scheduled, $feeKobo, $e) {
                $this->finish($scheduled, 'balance_due', $e->getMessage());
                $this->recordBalanceDue($ride, $scheduled, $feeKobo);
            });

            return;
        } catch (\RuntimeException $e) {
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
     * @return string|null The gateway transaction id, or null when another worker is already charging it
     *
     * @throws \DomainException when the passenger cannot pay by card (no card, declined)
     * @throws \RuntimeException when the outcome is unknown
     */
    private function chargeCard(Ride $ride, ScheduledRide $scheduled, int $feeKobo): ?string
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
            return null;
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

            throw new \RuntimeException('Card charge error; check the payment provider before charging again.');
        }

        if (($result['status'] ?? null) !== 'successful') {
            throw new \DomainException("Card charge status: {$result['status']}.");
        }

        return (string) $result['transaction_id'];
    }

    /**
     * The booking of a cancelled ride whose late-cancellation fee the
     * passenger can dispute, or null when there is none.
     */
    public function disputableFee(Ride $ride): ?ScheduledRide
    {
        if ($ride->status !== RideStatus::Cancelled) {
            return null;
        }

        $scheduled = ScheduledRide::where('ride_id', $ride->id)->first();

        return $scheduled !== null
            && (float) $scheduled->late_cancellation_fee > 0
            && in_array($scheduled->late_cancellation_fee_status, self::DISPUTABLE_STATUSES, true)
            ? $scheduled
            : null;
    }

    /**
     * What a dispute can still give back for the fee, in kobo: the part
     * collected and not yet refunded, or the whole fee while it is still
     * owed as a balance due (given back by waiving it). 0 while a charge is
     * still in progress.
     */
    public function refundableKobo(Ride $ride): int
    {
        $scheduled = $this->disputableFee($ride);

        if ($scheduled === null) {
            return 0;
        }

        $feeKobo = (int) round((float) $scheduled->late_cancellation_fee * 100);

        return match ($scheduled->late_cancellation_fee_status) {
            'charged' => max(0, $feeKobo - $this->refunds->refundedAmountKobo($ride)),
            'balance_due' => $this->outstandingBalance($ride) !== null ? $feeKobo : 0,
            default => 0,
        };
    }

    /**
     * Give a late-cancellation fee back after a dispute. A collected fee is
     * refunded to the passenger's wallet and, unless E-tiGo absorbs it, taken
     * back from the driver who received it in full. A fee still owed is
     * waived, in full: a balance due cannot be partly waived. A waiver pays
     * nothing back, so it is reported apart from a refund (waived_kobo).
     *
     * @return array{refund_kobo: int, clawback_kobo: int, waived_kobo: int}
     *
     * @throws \DomainException
     */
    public function refund(Ride $ride, int $amountKobo, string $reason, User $admin, bool $clawback, ?string $disputeId = null): array
    {
        return DB::transaction(function () use ($ride, $amountKobo, $reason, $admin, $clawback, $disputeId) {
            // Serialise refunds of the same ride, as WalletRefundService does.
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);
            $scheduled = $this->disputableFee($ride);
            $remaining = $this->refundableKobo($ride);

            if ($scheduled === null || $remaining <= 0) {
                throw new \DomainException('There is no cancellation fee left to refund on this ride.');
            }

            if ($amountKobo > $remaining) {
                throw new \DomainException('Refund exceeds remaining refundable amount of ₦'.number_format($remaining / 100, 2).'.');
            }

            if ($scheduled->late_cancellation_fee_status === 'balance_due') {
                if ($amountKobo !== $remaining) {
                    throw new \DomainException('An unpaid cancellation fee can only be waived in full (₦'.number_format($remaining / 100, 2).').');
                }

                $this->balanceService->waive($this->outstandingBalance($ride), $admin, $reason);

                return ['refund_kobo' => 0, 'clawback_kobo' => 0, 'waived_kobo' => $amountKobo];
            }

            $key = 'refund-'.$ride->id.'-'.Str::random(8);

            $this->refunds->refundToWallet($ride, $amountKobo, $reason, $key, [
                'dispute_id' => $disputeId,
                'refunded_by_admin_id' => $admin->id,
                'reason' => $reason,
                'category' => 'scheduled_late_cancellation_fee_refund',
            ]);

            $clawbackKobo = 0;
            $driver = $clawback ? $this->paidDriver($ride, $scheduled) : null;

            if ($driver !== null) {
                // The driver received the whole fee, so the whole refund comes back from them.
                $this->refunds->clawbackDriverEarnings($ride, $amountKobo, "clawback-{$key}", $driver);
                $clawbackKobo = $amountKobo;
            }

            AuditLog::record($ride, 'scheduled_ride_late_cancellation_fee_refunded', $admin, null, [
                'refund_kobo' => $amountKobo,
                'clawback_kobo' => $clawbackKobo,
                'dispute_id' => $disputeId,
            ]);

            return ['refund_kobo' => $amountKobo, 'clawback_kobo' => $clawbackKobo, 'waived_kobo' => 0];
        });
    }

    private function outstandingBalance(Ride $ride): ?PassengerBalanceDue
    {
        return PassengerBalanceDue::where('ride_id', $ride->id)
            ->where('reason', BalanceDueReason::LateCancellationFee)
            ->where('status', BalanceDueStatus::Outstanding)
            ->first();
    }

    /**
     * The driver the fee was paid to: charged at once, or paid later as a
     * balance due.
     */
    private function paidDriver(Ride $ride, ScheduledRide $scheduled): ?Driver
    {
        $driverId = Journal::where('idempotency_key', "scheduled-cancel-fee-{$scheduled->id}")->first()?->metadata['driver_id'] ?? null;

        if ($driverId !== null) {
            return Driver::find($driverId);
        }

        $payee = PassengerBalanceDue::where('ride_id', $ride->id)
            ->where('reason', BalanceDueReason::LateCancellationFee)
            ->where('status', BalanceDueStatus::Paid)
            ->value('payee_driver_id');

        return $payee !== null ? Driver::where('user_id', $payee)->first() : null;
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
