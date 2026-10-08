<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\UserPaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private PaymentGateway $paymentGateway,
    ) {}

    public function processRidePayment(Ride $ride): Payment
    {
        return match ($ride->payment_method) {
            PaymentMethod::Cash => $this->processCashPayment($ride),
            PaymentMethod::Card => $this->processCardPayment($ride),
        };
    }

    public function processCashPayment(Ride $ride): Payment
    {
        return DB::transaction(function () use ($ride) {
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

    public function confirmCashCollection(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment->update(['status' => PaymentStatus::Collected]);
            $payment->ride->update(['payment_status' => PaymentStatus::Collected]);

            return $payment->fresh();
        });
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

        return $payment->fresh();
    }

    public function refundPayment(Payment $payment, ?float $amount = null): Payment
    {
        $refundAmount = $amount ?? (float) $payment->amount;

        if (! $payment->gateway_transaction_id) {
            throw new \RuntimeException('Cannot refund a payment without a gateway transaction.');
        }

        $this->paymentGateway->refund($payment->gateway_transaction_id, $refundAmount);

        return DB::transaction(function () use ($payment) {
            $payment->update(['status' => PaymentStatus::Refunded]);
            $payment->ride->update(['payment_status' => PaymentStatus::Refunded]);

            return $payment->fresh();
        });
    }

    private function createFailedPayment(Ride $ride, string $reason): Payment
    {
        return DB::transaction(function () use ($ride, $reason) {
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
    }
}
