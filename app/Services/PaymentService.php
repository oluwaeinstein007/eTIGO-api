<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\PaymentUpdated;
use App\Models\Driver;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\UserPaymentMethod;
use App\Notifications\CashChangeWalletCreditNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private PaymentGateway $paymentGateway,
        private WalletPaymentService $walletPaymentService,
        private LedgerService $ledgerService,
    ) {}

    public function processRidePayment(Ride $ride): Payment
    {
        return match ($ride->payment_method) {
            PaymentMethod::Cash => $this->processCashPayment($ride),
            PaymentMethod::Card => $this->processCardPayment($ride),
            PaymentMethod::Wallet => $this->processWalletPayment($ride),
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
            return $this->createFailedPayment($ride, 'No default payment method on file.');
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
                'email' => $passenger->email,
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

            $maxBalance = config('wallet.max_balance', 50000000);
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

    public function addTip(Ride $ride, float $tipAmount): Payment
    {
        $payment = $ride->payment;

        if (! $payment) {
            throw new \RuntimeException('No payment record found for this ride.');
        }

        // Charge card OUTSIDE the DB transaction — external calls can't be
        // rolled back, so we charge first, then record the result.
        if ($ride->payment_method === PaymentMethod::Card && $tipAmount > 0) {
            $txRef = 'ETIGO-TIP-'.Str::upper(Str::random(12));
            $passenger = $ride->passenger;
            $defaultMethod = UserPaymentMethod::where('user_id', $passenger->id)
                ->where('is_default', true)
                ->first();

            if (! $defaultMethod) {
                throw new \RuntimeException('No default payment method on file to charge tip.');
            }

            try {
                $result = $this->paymentGateway->chargeWithToken([
                    'token' => $defaultMethod->gateway_token,
                    'email' => $passenger->email,
                    'amount' => $tipAmount,
                    'currency' => $ride->fare_currency,
                    'tx_ref' => $txRef,
                ]);

                if ($result['status'] !== 'successful') {
                    throw new \RuntimeException("Tip charge returned non-successful status: {$result['status']}");
                }
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Tip charge failed', [
                    'ride_id' => $ride->id,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException('Failed to charge tip amount.');
            }
        }

        $payment->update(['tip_amount' => $tipAmount]);

        $this->broadcastPaymentUpdate($payment->fresh());

        return $payment->fresh();
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
                        ? "Wallet shortfall: {$result['shortfall']} kobo"
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
