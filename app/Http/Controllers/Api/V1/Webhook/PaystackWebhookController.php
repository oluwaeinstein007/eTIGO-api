<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Events\PaymentUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPayoutWebhookJob;
use App\Jobs\ProcessTopupWebhookJob;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function __construct(
        private PaymentGateway $paymentGateway,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secretKey = config('services.paystack.secret_key');

        if (! $secretKey) {
            if (! app()->environment('local', 'testing')) {
                Log::error('Paystack webhook: secret key not configured');

                return response()->json(['message' => 'Webhook verification unavailable.'], 503);
            }
        }

        if ($secretKey) {
            $signature = $request->header('x-paystack-signature');
            $computed = hash_hmac('sha512', $request->getContent(), $secretKey);

            if (! $signature || ! hash_equals($computed, $signature)) {
                Log::warning('Paystack webhook: invalid signature');

                return response()->json(['message' => 'Invalid signature.'], 403);
            }
        }

        $payload = $request->all();
        $eventType = $payload['event'] ?? 'unknown';
        $data = $payload['data'] ?? [];
        $eventId = (string) ($data['id'] ?? $data['reference'] ?? '');

        $existing = WebhookEvent::where('provider', 'paystack')
            ->where('event_id', $eventId)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Already processed.']);
        }

        $webhookEvent = WebhookEvent::create([
            'provider' => 'paystack',
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => $payload,
            'signature' => $request->header('x-paystack-signature'),
            'created_at' => now(),
        ]);

        match ($eventType) {
            'charge.success' => $this->handleChargeSuccess($data, $webhookEvent),
            'transfer.success' => $this->handleTransferEvent($payload, $webhookEvent, 'paid'),
            'transfer.failed', 'transfer.reversed' => $this->handleTransferEvent($payload, $webhookEvent, 'failed'),
            default => Log::info("Paystack webhook: unhandled event type {$eventType}"),
        };

        return response()->json(['message' => 'Webhook received.']);
    }

    private function handleChargeSuccess(array $data, WebhookEvent $webhookEvent): void
    {
        $reference = $data['reference'] ?? '';
        $metadata = $data['metadata'] ?? [];
        $accountId = $metadata['account_id'] ?? null;
        $status = $data['status'] ?? '';

        if ($status === 'success' && $accountId && str_starts_with($reference, 'TOPUP-')) {
            $amount = (int) ($data['amount'] ?? 0);
            ProcessTopupWebhookJob::dispatch($accountId, $amount, $reference);
            $webhookEvent->markProcessed();

            return;
        }

        $this->handleRidePaymentCharge($data, $webhookEvent);
    }

    private function handleRidePaymentCharge(array $data, WebhookEvent $webhookEvent): void
    {
        $transactionId = (string) ($data['id'] ?? '');

        if (! $transactionId) {
            $webhookEvent->markProcessed();

            return;
        }

        $verification = $this->paymentGateway->verifyTransaction($transactionId);

        $payment = Payment::where('gateway_transaction_id', $transactionId)->first();

        if (! $payment && ! empty($verification['tx_ref'])) {
            $payment = Payment::where('gateway_tx_ref', $verification['tx_ref'])
                ->where('status', PaymentStatus::Pending)
                ->first();
        }

        if (! $payment) {
            Log::warning('Paystack webhook: no payment matched charge', [
                'transaction_id' => $transactionId,
                'tx_ref' => $verification['tx_ref'] ?? null,
            ]);
            $webhookEvent->markProcessed();

            return;
        }

        if (! $payment->gateway_transaction_id) {
            $payment->update(['gateway_transaction_id' => $transactionId]);
        }

        $terminalStatuses = [
            PaymentStatus::Refunded,
            PaymentStatus::Collected,
            PaymentStatus::Settled,
        ];

        if (in_array($payment->status, $terminalStatuses)) {
            Log::info('Paystack webhook: skipping update for terminal payment', [
                'payment_id' => $payment->id,
                'current_status' => $payment->status->value,
            ]);

            $webhookEvent->markProcessed();

            return;
        }

        if ($verification['status'] === 'successful') {
            $verifiedAmount = (float) $verification['amount'];
            $verifiedCurrency = $verification['currency'] ?? 'NGN';

            if ((float) $payment->amount !== $verifiedAmount || strcasecmp($payment->currency ?? 'NGN', $verifiedCurrency) !== 0) {
                Log::warning('Paystack webhook: amount/currency mismatch', [
                    'payment_id' => $payment->id,
                    'expected_amount' => $payment->amount,
                    'verified_amount' => $verifiedAmount,
                    'expected_currency' => $payment->currency,
                    'verified_currency' => $verifiedCurrency,
                ]);

                $payment->update([
                    'status' => PaymentStatus::Failed,
                    'failure_reason' => 'Amount or currency mismatch',
                ]);
                $payment->ride?->update(['payment_status' => PaymentStatus::Failed]);
                $webhookEvent->markProcessed();

                return;
            }

            $newStatus = PaymentStatus::Captured;
        } else {
            $newStatus = PaymentStatus::Failed;
        }

        $payment->update([
            'status' => $newStatus,
            'failure_reason' => $newStatus === PaymentStatus::Failed
                ? "Gateway status: {$verification['status']}"
                : null,
        ]);

        $payment->ride?->update(['payment_status' => $newStatus]);

        try {
            PaymentUpdated::dispatch($payment->fresh());
        } catch (\Throwable $exception) {
            Log::warning('Payment update broadcast failed', [
                'payment_id' => $payment->id,
                'ride_id' => $payment->ride_id,
                'error' => $exception->getMessage(),
            ]);
        }

        $webhookEvent->markProcessed();
    }

    private function handleTransferEvent(array $payload, WebhookEvent $webhookEvent, string $status): void
    {
        $data = $payload['data'] ?? [];
        $transferId = (string) ($data['id'] ?? '');

        if (! $transferId) {
            Log::warning('Paystack webhook: transfer event missing transfer ID');
            $webhookEvent->markProcessed();

            return;
        }

        $reason = $data['reason'] ?? ($data['narration'] ?? null);

        ProcessPayoutWebhookJob::dispatch($transferId, $status, $reason);

        $webhookEvent->markProcessed();
    }
}
