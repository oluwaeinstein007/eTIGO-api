<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\PaymentUpdated;
use App\Models\AppSetting;
use App\Models\Driver;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\UserPaymentMethod;
use App\Notifications\CashChangeWalletCreditNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private PaymentGateway $paymentGateway,
        private WalletPaymentService $walletPaymentService,
        private LedgerService $ledgerService,
        private DriverEarningsService $driverEarningsService,
    ) {}

    public function processRidePayment(Ride $ride): Payment
    {
        return match ($ride->payment_method) {
            PaymentMethod::Cash => $this->processCashPayment($ride),
            PaymentMethod::Card => $this->processCardPayment($ride),
            PaymentMethod::Wallet => $this->processWalletPayment($ride),
            PaymentMethod::BankTransfer => $this->processBankTransferPayment($ride),
        };
    }

    public function processCashPayment(Ride $ride): Payment
    {
        $payment = DB::transaction(function () use ($ride) {
            $payment = Payment::updateOrCreate(
                ['ride_id' => $ride->id],
                [
                    'amount' => $ride->final_fare_amount ?? $ride->fare_estimate_amount,
                    'currency' => $ride->fare_currency,
                    'method' => PaymentMethod::Cash,
                    'status' => PaymentStatus::PendingCollection,
                ],
            );

            $ride->update(['payment_status' => PaymentStatus::PendingCollection]);

            return $payment;
        });

        $this->broadcastPaymentUpdate($payment->fresh());

        return $payment;
    }

    public function processCardPayment(Ride $ride): Payment
    {
        $passenger = $ride->passenger;
        $defaultMethod = UserPaymentMethod::where('user_id', $passenger->id)
            ->where('is_default', true)
            ->first();

        if (! $defaultMethod) {
            return $this->processOneTimeCardPayment($ride);
        }

        $amount = (float) ($ride->final_fare_amount ?? $ride->fare_estimate_amount);
        $txRef = 'ETIGO-RIDE-'.Str::upper(Str::random(12));

        // Create a pending payment BEFORE calling the gateway so we have a
        // record even if the gateway call times out after capturing funds.
        $payment = DB::transaction(function () use ($ride, $defaultMethod, $amount) {
            return Payment::updateOrCreate(
                ['ride_id' => $ride->id],
                [
                    'amount' => $amount,
                    'currency' => $ride->fare_currency,
                    'method' => PaymentMethod::Card,
                    'gateway_payment_method_id' => $defaultMethod->id,
                    'status' => PaymentStatus::Pending,
                ],
            );
        });
        $this->broadcastPaymentUpdate($payment->fresh());

        try {
            $result = $this->paymentGateway->chargeWithToken([
                'token' => $defaultMethod->gateway_token,
                'provider' => $defaultMethod->provider,
                'email' => $defaultMethod->gateway_email ?? $passenger->paymentEmail(),
                'amount' => $amount,
                'currency' => $ride->fare_currency,
                'tx_ref' => $txRef,
            ]);

            $newStatus = $result['status'] === 'successful'
                ? PaymentStatus::Captured
                : PaymentStatus::Failed;

            $payment->update([
                'gateway_transaction_id' => $result['transaction_id'],
                'status' => $newStatus,
                'failure_reason' => $newStatus === PaymentStatus::Failed
                    ? "Charge status: {$result['status']}"
                    : null,
            ]);

            $ride->update(['payment_status' => $newStatus]);
            $this->broadcastPaymentUpdate($payment->fresh());
        } catch (\Throwable $e) {
            // Gateway exception — charge may have succeeded (timeout, network).
            // Leave payment as Pending so the webhook or manual reconciliation
            // can resolve it. Do NOT mark as Failed.
            Log::error('Card payment gateway error — payment left as pending for reconciliation', [
                'ride_id' => $ride->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $payment->fresh();
    }

    private function processOneTimeCardPayment(Ride $ride): Payment
    {
        $passenger = $ride->passenger;
        $amount = (float) ($ride->final_fare_amount ?? $ride->fare_estimate_amount);
        $txRef = 'ETIGO-RIDE-'.Str::upper(Str::random(12));

        $payment = DB::transaction(function () use ($ride, $amount, $txRef) {
            return Payment::updateOrCreate(
                ['ride_id' => $ride->id],
                [
                    'amount' => $amount,
                    'currency' => $ride->fare_currency,
                    'method' => PaymentMethod::Card,
                    'status' => PaymentStatus::Pending,
                    'gateway_tx_ref' => $txRef,
                ],
            );
        });
        $this->broadcastPaymentUpdate($payment->fresh());

        try {
            $result = $this->paymentGateway->initializePayment([
                'tx_ref' => $txRef,
                'amount' => $amount,
                'currency' => $ride->fare_currency,
                'redirect_url' => config('app.url').'/payments/callback',
                'customer_email' => $passenger->paymentEmail(),
                'channels' => ['card'],
            ]);

            $payment->update(['gateway_payment_link' => $result['payment_link']]);
        } catch (\Throwable $e) {
            Log::error('One-time card payment initialization failed', [
                'ride_id' => $ride->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $payment->fresh();
    }

    public function processBankTransferPayment(Ride $ride): Payment
    {
        $passenger = $ride->passenger;
        $amount = (float) ($ride->final_fare_amount ?? $ride->fare_estimate_amount);
        $txRef = 'ETIGO-RIDE-'.Str::upper(Str::random(12));

        $payment = DB::transaction(function () use ($ride, $amount, $txRef) {
            return Payment::updateOrCreate(
                ['ride_id' => $ride->id],
                [
                    'amount' => $amount,
                    'currency' => $ride->fare_currency,
                    'method' => PaymentMethod::BankTransfer,
                    'status' => PaymentStatus::Pending,
                    'gateway_tx_ref' => $txRef,
                ],
            );
        });
        $this->broadcastPaymentUpdate($payment->fresh());

        try {
            $result = $this->paymentGateway->initializePayment([
                'tx_ref' => $txRef,
                'amount' => $amount,
                'currency' => $ride->fare_currency,
                'redirect_url' => config('app.url').'/payments/callback',
                'customer_email' => $passenger->paymentEmail(),
            ]);

            $payment->update(['gateway_payment_link' => $result['payment_link']]);
        } catch (\Throwable $e) {
            Log::error('Bank transfer payment initialization failed', [
                'ride_id' => $ride->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $payment->fresh();
    }

    public function confirmCashCollection(Payment $payment, ?float $amountCollected = null): Payment
    {
        $ride = $payment->ride;
        $fareAmount = (float) $payment->amount;
        $changeAmount = null;
        $changeKobo = 0;

        if ($amountCollected !== null && $amountCollected > $fareAmount) {
            $changeAmount = round($amountCollected - $fareAmount, 2);
            $changeKobo = (int) round($changeAmount * 100);

            $maxOverpayment = config('wallet.max_cash_overpayment', 200000);
            if ($changeKobo > $maxOverpayment) {
                throw new \DomainException(
                    'Cash overpayment exceeds maximum allowed (₦'.number_format($maxOverpayment / 100, 2).').'
                );
            }

            $passengerAccount = $this->ledgerService->findOrCreateAccount(
                'App\\Models\\User',
                $ride->passenger_id,
                AccountType::PassengerWallet,
            );

            $maxBalance = AppSetting::wallet('max_balance');
            if (($passengerAccount->balance + $changeKobo) > $maxBalance) {
                throw new \DomainException(
                    'Cash change would exceed rider wallet maximum balance (₦'.number_format($maxBalance / 100, 2).').'
                );
            }
        }

        $payment = DB::transaction(function () use ($payment, $ride, $amountCollected, $changeAmount, $changeKobo) {
            $updateData = ['status' => PaymentStatus::Collected];

            if ($amountCollected !== null) {
                $updateData['amount_collected'] = $amountCollected;
            }

            if ($changeAmount !== null && $changeAmount > 0) {
                $updateData['cash_change_amount'] = $changeAmount;

                $driver = Driver::where('user_id', $ride->driver_id)->firstOrFail();

                $driverAccount = $this->ledgerService->findOrCreateAccount(
                    'App\\Models\\Driver',
                    $driver->id,
                    AccountType::DriverEarningsAvailable,
                );

                $passengerAccount = $this->ledgerService->findOrCreateAccount(
                    'App\\Models\\User',
                    $ride->passenger_id,
                    AccountType::PassengerWallet,
                );

                $this->ledgerService->postJournal([
                    ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $changeKobo],
                    ['account_id' => $passengerAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $changeKobo],
                ], [
                    'description' => "Cash change credit for ride {$ride->id}",
                    'idempotency_key' => "cash-change-{$ride->id}",
                    'metadata' => [
                        'ride_id' => $ride->id,
                        'fare_amount' => (float) $payment->amount,
                        'amount_collected' => $amountCollected,
                        'change_amount' => $changeAmount,
                        'change_kobo' => $changeKobo,
                    ],
                ]);
            }

            $payment->update($updateData);
            $ride->update(['payment_status' => PaymentStatus::Collected]);

            return $payment->fresh();
        });

        $this->broadcastPaymentUpdate($payment->fresh());

        if ($changeKobo > 0) {
            $ride->passenger->notify(new CashChangeWalletCreditNotification($changeKobo, $ride->id));
        }

        return $payment;
    }

    public const TIP_UNCONFIRMED_MESSAGE = 'We could not confirm your tip payment with the bank yet. Please try again in a few minutes; you will not be charged twice.';

    /**
     * @param  PaymentMethod|null  $source  How the tip is paid (card, wallet or cash);
     *                                      defaults to the ride's method. Bank-transfer
     *                                      rides must name one.
     */
    public function addTip(Ride $ride, float $tipAmount, ?PaymentMethod $source = null): Payment
    {
        $payment = $ride->payment;

        if (! $payment) {
            throw new \RuntimeException('No payment record found for this ride.');
        }

        $source ??= $ride->payment_method;

        if (! in_array($source, [PaymentMethod::Card, PaymentMethod::Wallet, PaymentMethod::Cash], true)) {
            throw new \RuntimeException('Choose how to pay the tip: wallet or card.');
        }

        if ($source === PaymentMethod::Cash && $ride->payment_method !== PaymentMethod::Cash) {
            throw new \RuntimeException('Cash tips can only be recorded on cash rides.');
        }

        if ($this->hasUnconfirmedCardTip($payment) && $this->resolveUnconfirmedCardTip($ride, $payment) === 'paid') {
            throw new \RuntimeException('Your earlier tip went through, so you were not charged again.');
        }

        $card = null;
        $reference = null;

        if ($source === PaymentMethod::Card && $tipAmount > 0) {
            $card = UserPaymentMethod::where('user_id', $ride->passenger_id)->where('is_default', true)->first()
                ?? throw new \RuntimeException('No default payment method on file to charge tip.');
            $reference = 'ETIGO-TIP-'.Str::upper(Str::random(12));
        }

        // Claim the tip slot before any charge so concurrent requests cannot
        // both charge the passenger's card.
        $claimed = Payment::whereKey($payment->id)
            ->where(fn ($query) => $query->whereNull('tip_amount')->orWhere('tip_amount', 0))
            ->update(['tip_amount' => $tipAmount, 'tip_method' => $source, 'tip_reference' => $reference]);

        if ($claimed === 0) {
            throw new \RuntimeException('A tip has already been added to this ride.');
        }

        if ($card !== null) {
            $this->collectCardTip($ride, $payment, $card, $tipAmount, $reference);
        }

        if ($source === PaymentMethod::Wallet && $tipAmount > 0) {
            try {
                $this->walletPaymentService->payTip($ride, (int) round($tipAmount * 100));
            } catch (\Throwable $e) {
                $this->releaseTip($payment);

                throw new \RuntimeException($e instanceof \DomainException ? $e->getMessage() : 'Failed to pay the tip from your wallet.');
            }
        }

        $this->broadcastPaymentUpdate($payment->fresh());

        return $payment->fresh();
    }

    /**
     * A card tip whose charge outcome was never confirmed: the attempt's
     * reference is kept and nothing has been credited to the driver yet.
     */
    public function hasUnconfirmedCardTip(Payment $payment): bool
    {
        return $payment->tip_method === PaymentMethod::Card
            && $payment->tip_reference !== null
            && ! Journal::where('idempotency_key', "card-tip-{$payment->ride_id}")->exists();
    }

    /**
     * External charges cannot be rolled back, so this runs outside any
     * database transaction. A definite decline releases the tip slot; a
     * timeout or provider error keeps it, with the attempt's reference, and
     * asks the provider what happened before anything is charged again.
     */
    private function collectCardTip(Ride $ride, Payment $payment, UserPaymentMethod $card, float $tipAmount, string $reference): void
    {
        try {
            $result = $this->paymentGateway->chargeWithToken([
                'token' => $card->gateway_token,
                'provider' => $card->provider,
                'email' => $card->gateway_email ?? $ride->passenger->paymentEmail(),
                'amount' => $tipAmount,
                'currency' => $ride->fare_currency,
                'tx_ref' => $reference,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Tip charge failed', ['ride_id' => $ride->id, 'reference' => $reference, 'error' => $e->getMessage()]);

            if (! $this->isUncertainGatewayFailure($e)) {
                $this->releaseTip($payment);

                throw new \RuntimeException('Failed to charge tip amount.');
            }

            if ($this->resolveUnconfirmedCardTip($ride, $payment->fresh()) === 'paid') {
                return;
            }

            throw new \RuntimeException('Failed to charge tip amount.');
        }

        if ($result['status'] !== 'successful') {
            $this->releaseTip($payment);

            throw new \RuntimeException("Tip charge returned non-successful status: {$result['status']}");
        }

        $this->creditCardTip($ride, $tipAmount);
    }

    /**
     * Ask the provider about an unconfirmed card tip by its reference.
     *
     * @return 'paid'|'failed'
     *
     * @throws \RuntimeException when the outcome is still unknown (the tip stays reserved)
     */
    private function resolveUnconfirmedCardTip(Ride $ride, Payment $payment): string
    {
        try {
            $transaction = $this->paymentGateway->verifyTransaction($payment->tip_reference);
        } catch (\Throwable $e) {
            $provider = UserPaymentMethod::where('user_id', $ride->passenger_id)->where('is_default', true)->value('provider');

            // Paystack answers an unknown reference with a client error: the charge never happened.
            if ($provider === 'paystack' && $e instanceof RequestException && $e->response->clientError() && $e->response->status() !== 429) {
                $this->releaseTip($payment);

                return 'failed';
            }

            Log::critical('Card tip charge outcome unknown', ['ride_id' => $ride->id, 'reference' => $payment->tip_reference, 'error' => $e->getMessage()]);

            throw new \RuntimeException(self::TIP_UNCONFIRMED_MESSAGE);
        }

        $status = strtolower((string) ($transaction['status'] ?? ''));

        if ($status === 'successful') {
            $this->creditCardTip($ride, (float) $payment->tip_amount);

            return 'paid';
        }

        if (in_array($status, ['failed', 'abandoned', 'reversed', 'cancelled'], true)) {
            $this->releaseTip($payment);

            return 'failed';
        }

        throw new \RuntimeException(self::TIP_UNCONFIRMED_MESSAGE);
    }

    private function creditCardTip(Ride $ride, float $tipAmount): void
    {
        try {
            $this->driverEarningsService->creditTip($ride, (int) round($tipAmount * 100));
        } catch (\Throwable $e) {
            // The passenger has been charged; surface the missing credit for
            // reconciliation rather than failing the request.
            Log::critical('Card tip charged but not credited to driver', [
                'ride_id' => $ride->id,
                'tip_amount' => $tipAmount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function releaseTip(Payment $payment): void
    {
        Payment::whereKey($payment->id)->update(['tip_amount' => 0, 'tip_method' => null, 'tip_reference' => null]);
    }

    /** A timeout, server error or throttling says nothing about whether the charge happened. */
    private function isUncertainGatewayFailure(\Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429));
    }

    public function refundPayment(Payment $payment, ?float $amount = null): Payment
    {
        $refundAmount = $amount ?? (float) $payment->amount;

        if (! $payment->gateway_transaction_id) {
            throw new \RuntimeException('Cannot refund a payment without a gateway transaction.');
        }

        $this->paymentGateway->refund($payment->gateway_transaction_id, $refundAmount);

        $payment = DB::transaction(function () use ($payment) {
            $payment->update(['status' => PaymentStatus::Refunded]);
            $payment->ride->update(['payment_status' => PaymentStatus::Refunded]);

            return $payment->fresh();
        });

        $this->broadcastPaymentUpdate($payment->fresh());

        return $payment;
    }

    public function processWalletPayment(Ride $ride): Payment
    {
        $existing = Payment::where('ride_id', $ride->id)
            ->whereIn('status', [PaymentStatus::Captured, PaymentStatus::Failed])
            ->first();

        if ($existing) {
            return $existing;
        }

        $finalFareKobo = (int) round(($ride->final_fare_amount ?? $ride->fare_estimate_amount) * 100);

        try {
            $result = $this->walletPaymentService->settle($ride, $finalFareKobo);
        } catch (\DomainException $e) {
            $existingPayment = Payment::where('ride_id', $ride->id)
                ->whereIn('status', [PaymentStatus::Captured, PaymentStatus::Failed])
                ->first();

            if ($existingPayment) {
                return $existingPayment;
            }

            return $this->createFailedPayment($ride, $e->getMessage());
        }

        $status = $result['settled'] ? PaymentStatus::Captured : PaymentStatus::Failed;

        return DB::transaction(function () use ($ride, $finalFareKobo, $status, $result) {
            $payment = Payment::updateOrCreate(
                ['ride_id' => $ride->id],
                [
                    'amount' => $finalFareKobo / 100,
                    'currency' => $ride->fare_currency,
                    'method' => PaymentMethod::Wallet,
                    'status' => $status,
                    'failure_reason' => $result['shortfall'] > 0
                        ? "Wallet shortfall: {$result['shortfall']} kobo, recorded as a balance due"
                        : null,
                ],
            );

            $ride->update(['payment_status' => $status]);

            return $payment;
        });
    }

    private function createFailedPayment(Ride $ride, string $reason): Payment
    {
        $payment = DB::transaction(function () use ($ride, $reason) {
            $payment = Payment::updateOrCreate(
                ['ride_id' => $ride->id],
                [
                    'amount' => $ride->final_fare_amount ?? $ride->fare_estimate_amount,
                    'currency' => $ride->fare_currency,
                    'method' => $ride->payment_method,
                    'status' => PaymentStatus::Failed,
                    'failure_reason' => Str::limit($reason, 500),
                ],
            );

            $ride->update(['payment_status' => PaymentStatus::Failed]);

            return $payment;
        });

        $this->broadcastPaymentUpdate($payment->fresh());

        return $payment;
    }

    private function broadcastPaymentUpdate(Payment $payment): void
    {
        try {
            PaymentUpdated::dispatch($payment);
        } catch (\Throwable $exception) {
            Log::warning('Payment update broadcast failed', [
                'payment_id' => $payment->id,
                'ride_id' => $payment->ride_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
