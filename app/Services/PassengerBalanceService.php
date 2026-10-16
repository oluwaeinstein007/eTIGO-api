<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\AccountType;
use App\Enums\BalanceDueReason;
use App\Enums\BalanceDueStatus;
use App\Enums\LedgerEntryType;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\PassengerBalanceDue;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\UserPaymentMethod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Money a passenger owes that could not be collected at the time: a
 * scheduled ride's late-cancellation fee, or the part of a wallet fare the
 * wallet could not cover. While any is outstanding the passenger cannot
 * book (Uber/Bolt practice, `block_booking_with_balance_due`); they pay it
 * from the wallet or their saved card, and the money goes to the driver it
 * is owed to. Finance can waive a balance.
 */
class PassengerBalanceService
{
    public function __construct(
        private LedgerService $ledgerService,
        private CommissionService $commissionService,
        private PaymentGateway $paymentGateway,
        private NotificationService $notificationService,
    ) {}

    /**
     * Record a balance due (once per ride and reason) and tell the passenger.
     */
    public function record(
        string $passengerId,
        ?Ride $ride,
        BalanceDueReason $reason,
        int $amountKobo,
        ?string $payeeDriverId,
    ): ?PassengerBalanceDue {
        if ($amountKobo <= 0) {
            return null;
        }

        $balance = PassengerBalanceDue::firstOrCreate(
            ['ride_id' => $ride?->id, 'reason' => $reason],
            [
                'passenger_id' => $passengerId,
                'amount' => $amountKobo / 100,
                'currency' => $ride?->fare_currency ?? 'NGN',
                'payee_driver_id' => $payeeDriverId,
                'status' => BalanceDueStatus::Outstanding,
            ],
        );

        if ($balance->wasRecentlyCreated) {
            AuditLog::record($balance, 'balance_due_recorded', null, null, [
                'reason' => $reason->value,
                'amount' => $balance->amount,
                'ride_id' => $ride?->id,
            ]);

            DB::afterCommit(fn () => $this->notifyRecorded($balance));
        }

        return $balance;
    }

    /**
     * @return Collection<int, PassengerBalanceDue>
     */
    public function outstanding(User $passenger): Collection
    {
        return PassengerBalanceDue::query()
            ->where('passenger_id', $passenger->id)
            ->outstanding()
            ->with('ride')
            ->oldest()
            ->get();
    }

    /**
     * Total outstanding, in NGN.
     */
    public function outstandingTotal(User $passenger): float
    {
        return round((float) PassengerBalanceDue::query()
            ->where('passenger_id', $passenger->id)
            ->outstanding()
            ->sum('amount'), 2);
    }

    /**
     * The outstanding total when it should stop the passenger booking, else null.
     */
    public function blockingTotal(User $passenger): ?float
    {
        if (! AppSetting::wallet('block_booking_with_balance_due')) {
            return null;
        }

        $total = $this->outstandingTotal($passenger);

        return $total > 0 ? $total : null;
    }

    /**
     * Pay everything outstanding in one go, from the wallet or the saved
     * default card.
     *
     * @param  'wallet'|'card'  $method
     * @return Collection<int, PassengerBalanceDue> The balances paid
     *
     * @throws \DomainException when nothing is owed or the payment fails
     */
    public function pay(User $passenger, string $method): Collection
    {
        $lock = Cache::lock("balance-due-pay:{$passenger->id}", 60);

        if (! $lock->get()) {
            throw new \DomainException('A payment for your balance is already in progress.');
        }

        try {
            return $method === 'wallet'
                ? $this->payFromWallet($passenger)
                : $this->payByCard($passenger);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return Collection<int, PassengerBalanceDue>
     */
    private function payFromWallet(User $passenger): Collection
    {
        return DB::transaction(function () use ($passenger) {
            $items = $this->lockOutstanding($passenger);
            $totalKobo = (int) $items->sum(fn (PassengerBalanceDue $item) => $item->amountKobo());

            $wallet = $this->ledgerService->findOrCreateAccount($passenger->getMorphClass(), $passenger->id, AccountType::PassengerWallet);
            $wallet = Account::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            if (! $wallet->isActive()) {
                throw new \DomainException('Wallet is frozen or closed.');
            }

            if ($wallet->availableBalance() < $totalKobo) {
                throw new \DomainException('Your wallet balance cannot cover the ₦'.number_format($totalKobo / 100, 2).' you owe. Top up or pay by card.');
            }

            foreach ($items as $item) {
                $this->settle($item, $wallet, 'wallet', null);
            }

            return $items;
        });
    }

    /**
     * @return Collection<int, PassengerBalanceDue>
     */
    private function payByCard(User $passenger): Collection
    {
        $card = UserPaymentMethod::where('user_id', $passenger->id)->where('is_default', true)->first();

        if ($card === null) {
            throw new \DomainException('Add a card to pay your balance, or pay from your wallet.');
        }

        $items = $this->outstanding($passenger);
        $totalKobo = (int) $items->sum(fn (PassengerBalanceDue $item) => $item->amountKobo());

        if ($totalKobo <= 0) {
            throw new \DomainException('You have no balance to pay.');
        }

        try {
            $result = $this->paymentGateway->chargeWithToken([
                'token' => $card->gateway_token,
                'provider' => $card->provider,
                'email' => $card->gateway_email ?? $passenger->paymentEmail(),
                'amount' => $totalKobo / 100,
                'currency' => $items->first()->currency ?? 'NGN',
                'tx_ref' => 'ETIGO-BAL-'.Str::upper(Str::random(12)),
            ]);
        } catch (\Throwable $e) {
            Log::error('Balance due card charge errored', ['passenger_id' => $passenger->id, 'error' => $e->getMessage()]);

            throw new \DomainException('We could not reach the card provider. Please try again shortly or pay from your wallet.');
        }

        if (($result['status'] ?? null) !== 'successful') {
            throw new \DomainException('Your card was declined. Try another card or pay from your wallet.');
        }

        return DB::transaction(function () use ($items, $result) {
            $clearing = $this->ledgerService->systemAccount(AccountType::PspClearing);
            $paid = collect();

            foreach ($items as $item) {
                $item = PassengerBalanceDue::whereKey($item->id)->lockForUpdate()->first();

                if ($item?->status === BalanceDueStatus::Outstanding) {
                    $this->settle($item, $clearing, 'card', (string) $result['transaction_id']);
                    $paid->push($item);
                }
            }

            return $paid;
        });
    }

    /**
     * @return Collection<int, PassengerBalanceDue>
     */
    private function lockOutstanding(User $passenger): Collection
    {
        $items = PassengerBalanceDue::query()
            ->where('passenger_id', $passenger->id)
            ->outstanding()
            ->oldest()
            ->lockForUpdate()
            ->get();

        if ($items->isEmpty()) {
            throw new \DomainException('You have no balance to pay.');
        }

        return $items;
    }

    /**
     * Pass one balance on to whoever it is owed to and close it.
     */
    private function settle(PassengerBalanceDue $item, Account $payer, string $via, ?string $reference): void
    {
        $amountKobo = $item->amountKobo();
        $driver = $item->payee_driver_id ? Driver::where('user_id', $item->payee_driver_id)->first() : null;

        if ($driver === null) {
            $this->ledgerService->postJournal([
                ['account_id' => $payer->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $amountKobo],
                ['account_id' => $this->ledgerService->systemAccount(AccountType::PlatformFees)->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $amountKobo],
            ], $this->journalOptions($item, $via, 0));
        } else {
            $driverAccount = $this->ledgerService->findOrCreateAccount(
                $driver->getMorphClass(),
                $driver->id,
                AppSetting::wallet('settlement_delay') === 'instant'
                    ? AccountType::DriverEarningsAvailable
                    : AccountType::DriverEarningsPending,
            );

            // An unpaid fare carries the usual commission; a late-cancellation
            // fee goes to the driver in full.
            $commission = $item->reason === BalanceDueReason::WalletShortfall
                ? $this->commissionService->calculate($amountKobo, $driver->id)['commission']
                : 0;

            $lines = [
                ['account_id' => $payer->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $amountKobo],
                ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $amountKobo - $commission],
            ];

            if ($commission > 0) {
                $lines[] = ['account_id' => $this->ledgerService->systemAccount(AccountType::PlatformCommission)->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $commission];
            }

            $this->ledgerService->postJournal($lines, $this->journalOptions($item, $via, $commission));
        }

        $item->update([
            'status' => BalanceDueStatus::Paid,
            'paid_at' => now(),
            'paid_via' => $via,
            'payment_reference' => $reference,
        ]);

        $this->closeSource($item, 'charged');

        AuditLog::record($item, 'balance_due_paid', $item->passenger, null, ['via' => $via, 'amount' => $item->amount]);
    }

    /**
     * @return array{description: string, idempotency_key: string, metadata: array<string, mixed>}
     */
    private function journalOptions(PassengerBalanceDue $item, string $via, int $commissionKobo): array
    {
        return [
            'description' => "Balance due ({$item->reason->value}) paid for ride {$item->ride_id}",
            'idempotency_key' => "balance-due-{$item->id}",
            'metadata' => [
                'balance_due_id' => $item->id,
                'ride_id' => $item->ride_id,
                'reason' => $item->reason->value,
                'amount_kobo' => $item->amountKobo(),
                'commission_kobo' => $commissionKobo,
                'paid_via' => $via,
                'category' => 'balance_due',
            ],
        ];
    }

    /**
     * Finance writes a balance off; nobody is paid.
     *
     * @throws \DomainException when it is no longer outstanding
     */
    public function waive(PassengerBalanceDue $item, User $admin, string $reason): PassengerBalanceDue
    {
        return DB::transaction(function () use ($item, $admin, $reason) {
            $item = PassengerBalanceDue::whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($item->status !== BalanceDueStatus::Outstanding) {
                throw new \DomainException('This balance is no longer outstanding.');
            }

            $item->update([
                'status' => BalanceDueStatus::Waived,
                'waived_at' => now(),
                'waived_by_admin_id' => $admin->id,
                'waive_reason' => $reason,
            ]);

            $this->closeSource($item, 'waived');

            AuditLog::record($item, 'balance_due_waived', $admin, null, ['reason' => $reason, 'amount' => $item->amount]);

            return $item;
        });
    }

    /**
     * Bring the ride's own records in line once the balance is settled.
     */
    private function closeSource(PassengerBalanceDue $item, string $feeStatus): void
    {
        if ($item->ride_id === null) {
            return;
        }

        if ($item->reason === BalanceDueReason::LateCancellationFee) {
            ScheduledRide::where('ride_id', $item->ride_id)->update(['late_cancellation_fee_status' => $feeStatus]);

            return;
        }

        if ($item->status === BalanceDueStatus::Paid) {
            // The fare is now paid in full.
            Payment::where('ride_id', $item->ride_id)->update(['status' => PaymentStatus::Captured, 'failure_reason' => null]);
            Ride::whereKey($item->ride_id)->update(['payment_status' => PaymentStatus::Captured->value]);
        }
    }

    private function notifyRecorded(PassengerBalanceDue $item): void
    {
        $amount = '₦'.number_format((float) $item->amount, 2);
        $body = $item->reason === BalanceDueReason::LateCancellationFee
            ? "You owe a {$amount} late cancellation fee for your scheduled ride. Pay it in the app before your next booking."
            : "Your wallet did not cover your last ride. You owe {$amount}; pay it in the app before your next booking.";

        try {
            $this->notificationService->send(
                userId: $item->passenger_id,
                type: NotificationType::BalanceDue,
                title: 'Balance Due',
                body: $body,
                data: ['balance_due_id' => (string) $item->id, 'ride_id' => (string) $item->ride_id, 'amount' => (string) $item->amount],
            );
        } catch (\Throwable $e) {
            Log::warning('Balance due notification failed', ['balance_due_id' => $item->id, 'error' => $e->getMessage()]);
        }
    }
}
