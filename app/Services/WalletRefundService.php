<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Driver;
use App\Models\Journal;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\WalletRefundNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletRefundService
{
    public function __construct(
        private LedgerService $ledgerService,
        private CommissionService $commissionService,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function refundToWallet(
        Ride $ride,
        int $amountKobo,
        ?string $reason = null,
        ?string $refundKey = null,
        array $metadata = [],
    ): void {
        $key = $refundKey ?? 'refund-'.$ride->id.'-'.Str::random(8);

        $passengerAccount = $this->ledgerService->findOrCreateAccount(
            'App\\Models\\User',
            $ride->passenger_id,
            AccountType::PassengerWallet,
        );

        $refundsAccount = $this->ledgerService->systemAccount(AccountType::Refunds);

        $this->ledgerService->postJournal([
            ['account_id' => $refundsAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $amountKobo],
            ['account_id' => $passengerAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $amountKobo],
        ], [
            'description' => $reason ?? "Refund for ride {$ride->id}",
            'idempotency_key' => $key,
            'metadata' => [
                'ride_id' => $ride->id,
                'refund_amount_kobo' => $amountKobo,
                ...$metadata,
            ],
        ]);

        // Only tell the passenger once the refund has actually been committed.
        DB::afterCommit(fn () => rescue(fn () => User::find($ride->passenger_id)
            ?->notify(new WalletRefundNotification($ride->id, $amountKobo))));
    }

    public function clawbackDriverEarnings(Ride $ride, int $amountKobo, ?string $clawbackKey = null): void
    {
        $key = $clawbackKey ?? 'clawback-'.$ride->id.'-'.Str::random(8);

        $driver = Driver::where('user_id', $ride->driver_id)->firstOrFail();

        $driverAccount = $this->ledgerService->findOrCreateAccount(
            'App\\Models\\Driver',
            $driver->id,
            AccountType::DriverEarningsAvailable,
        );

        $refundsAccount = $this->ledgerService->systemAccount(AccountType::Refunds);

        $this->ledgerService->postJournal([
            ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $amountKobo],
            ['account_id' => $refundsAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $amountKobo],
        ], [
            'description' => "Driver earnings clawback for ride {$ride->id}",
            'idempotency_key' => $key,
            'metadata' => [
                'ride_id' => $ride->id,
                'clawback_amount_kobo' => $amountKobo,
            ],
        ]);
    }

    /**
     * Refund part or all of a completed ride's fare to the passenger's wallet
     * and, unless the platform is absorbing the cost, recover the driver's
     * proportional share of that amount from their earnings.
     *
     * @return array{refund_kobo: int, clawback_kobo: int}
     */
    public function refundRide(
        Ride $ride,
        int $amountKobo,
        string $reason,
        User $admin,
        bool $clawbackDriverEarnings = true,
        ?string $disputeId = null,
    ): array {
        return DB::transaction(function () use ($ride, $amountKobo, $reason, $admin, $clawbackDriverEarnings, $disputeId) {
            // Serialise refunds of the same ride so two requests (a double
            // click, or two finance admins) cannot both refund what is left.
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);
            $remainingKobo = $this->remainingRefundableKobo($ride);

            if ($amountKobo > $remainingKobo) {
                throw new \DomainException('Refund exceeds remaining refundable amount of ₦'.number_format($remainingKobo / 100, 2).'.');
            }

            $refundKey = 'refund-'.$ride->id.'-'.Str::random(8);

            $this->refundToWallet($ride, $amountKobo, $reason, $refundKey, [
                'dispute_id' => $disputeId ?? $ride->disputes()->latest()->value('id'),
                'refunded_by_admin_id' => $admin->id,
                'reason' => $reason,
            ]);

            $clawbackKobo = 0;

            if ($clawbackDriverEarnings && $ride->driver_id) {
                $clawbackKobo = (int) round($amountKobo * $this->driverShareRatio($ride));

                if ($clawbackKobo > 0) {
                    $this->clawbackDriverEarnings($ride, $clawbackKobo, "clawback-{$refundKey}");
                }
            }

            return ['refund_kobo' => $amountKobo, 'clawback_kobo' => $clawbackKobo];
        });
    }

    /**
     * What can still be refunded for the ride, in kobo.
     */
    public function remainingRefundableKobo(Ride $ride): int
    {
        return max(0, $this->paidAmountKobo($ride) - $this->refundedAmountKobo($ride));
    }

    /**
     * What the passenger actually paid for the ride's fare, in kobo (tips are
     * the driver's and are not refundable here).
     */
    public function paidAmountKobo(Ride $ride): int
    {
        $payment = $ride->payment;

        if (! $payment) {
            return 0;
        }

        $paidStatuses = [
            PaymentStatus::Captured,
            PaymentStatus::Settled,
            PaymentStatus::Collected,
            PaymentStatus::PendingCollection,
        ];

        if (in_array($payment->status, $paidStatuses, true)) {
            return (int) round((float) $payment->amount * 100);
        }

        // A wallet ride settled with a shortfall still collected part of the fare.
        if ($payment->method === PaymentMethod::Wallet && $payment->status === PaymentStatus::Failed) {
            $settlement = Journal::where('idempotency_key', "ride-settlement-{$ride->id}")->first();

            return (int) ($settlement?->metadata['charged_kobo'] ?? 0);
        }

        return 0;
    }

    /**
     * Total already refunded to the passenger's wallet for this ride, in kobo.
     */
    public function refundedAmountKobo(Ride $ride): int
    {
        return (int) Journal::where('journals.idempotency_key', 'like', "refund-{$ride->id}-%")
            ->join('ledger_entries', 'journals.id', '=', 'ledger_entries.journal_id')
            ->where('ledger_entries.type', LedgerEntryType::Credit->value)
            ->sum('ledger_entries.amount');
    }

    /**
     * The fraction of the fare that ended up with the driver, taken from how
     * the ride was actually settled.
     */
    private function driverShareRatio(Ride $ride): float
    {
        $fleetRemittance = $ride->pricing_snapshot['fleet_remittance'] ?? null;

        if ($fleetRemittance !== null) {
            $total = (float) $fleetRemittance['remittance_share'] + (float) $fleetRemittance['driver_share'];

            return $total > 0 ? (float) $fleetRemittance['driver_share'] / $total : 0.0;
        }

        $settlement = Journal::whereIn('idempotency_key', [
            "ride-settlement-{$ride->id}",
            "card-settlement-{$ride->id}",
        ])->first();

        if ($settlement) {
            $fareKobo = (int) ($settlement->metadata['charged_kobo'] ?? $settlement->metadata['fare_kobo'] ?? 0);

            return $fareKobo > 0
                ? (int) ($settlement->metadata['net_earnings_kobo'] ?? 0) / $fareKobo
                : 0.0;
        }

        $cashCommission = Journal::where('idempotency_key', "cash-commission-{$ride->id}")->first();

        if ($cashCommission) {
            $fareKobo = (int) ($cashCommission->metadata['fare_kobo'] ?? 0);

            return $fareKobo > 0
                ? ($fareKobo - (int) ($cashCommission->metadata['commission_kobo'] ?? 0)) / $fareKobo
                : 0.0;
        }

        $driver = Driver::where('user_id', $ride->driver_id)->first();

        return $driver ? 1 - $this->commissionService->getRate($driver->id) : 0.0;
    }
}
