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
use App\Services\FlutterwavePaymentGateway;
use App\Services\RideSettlementService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FlutterwaveWebhookController extends Controller
{
    public function __construct(
        private PaymentGateway $paymentGateway,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secretHash = config('services.flutterwave.webhook_secret');

        if (! $secretHash) {
            if (! app()->environment('local', 'testing')) {
                Log::error('Flutterwave webhook: secret hash not configured');

                return response()->json(['message' => 'Webhook verification unavailable.'], 503);
            }
        }

        if ($secretHash) {
            $signature = $request->header('verif-hash');

            if (! $signature || ! hash_equals($secretHash, (string) $signature)) {
                Log::warning('Flutterwave webhook: invalid signature');

                return response()->json(['message' => 'Invalid signature.'], 403);
            }
        }

        $payload = $request->all();
        $eventType = $payload['event'] ?? 'unknown';
        $data = $payload['data'] ?? [];
        $eventId = (string) ($data['id'] ?? $data['tx_ref'] ?? '');

        $webhookEvent = WebhookEvent::where('provider', 'flutterwave')
            ->where('event_id', $eventId)
            ->where('event_type', $eventType)
            ->first();

        // Only completed events are skipped: if processing failed part-way
        // (e.g. the provider's verify API timed out), the provider's retry is
        // processed again. Every handler below is idempotent.
        if ($webhookEvent?->isProcessed()) {
            return response()->json(['message' => 'Already processed.']);
        }

        if (! $webhookEvent) {
            try {
                $webhookEvent = WebhookEvent::create([
                    'provider' => 'flutterwave',
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'payload' => $payload,
                    'signature' => $request->header('verif-hash'),
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent delivery of the same event won the race.
                return response()->json(['message' => 'Already processed.']);
            }
        }

        match ($eventType) {
            'charge.completed' => $this->handleChargeCompleted($data, $webhookEvent),
            // Flutterwave sends transfer.completed for failed transfers too.
            'transfer.completed' => $this->handleTransferEvent(
                $payload,
                $webhookEvent,
                strtoupper((string) ($data['status'] ?? '')) === 'SUCCESSFUL' ? 'paid' : 'failed',
            ),
            'transfer.failed' => $this->handleTransferEvent($payload, $webhookEvent, 'failed'),
            default => Log::info("Flutterwave webhook: unhandled event type {$eventType}"),
        };

        return response()->json(['message' => 'Webhook received.']);
    }

    private function handleChargeCompleted(array $data, WebhookEvent $webhookEvent): void
    {
        $txRef = $data['tx_ref'] ?? '';
        $status = $data['status'] ?? '';

        if ($status === 'successful' && str_starts_with($txRef, 'TOPUP-')) {
            $metadata = $data['meta'] ?? [];
            $accountId = $metadata['account_id'] ?? null;
            $amount = (int) (($data['amount'] ?? 0) * 100);

            if ($accountId) {
                ProcessTopupWebhookJob::dispatch($accountId, $amount, $txRef);
                $webhookEvent->markProcessed();

                return;
            }
        }

        $this->handleRidePaymentCharge($data, $webhookEvent);
    }

    private function handleRidePaymentCharge(array $data, WebhookEvent $webhookEvent): void
    {
        $transactionId = (string) ($data['id'] ?? '');
        $txRef = $data['tx_ref'] ?? '';

        if (! $transactionId) {
            $webhookEvent->markProcessed();

            return;
        }

        $flutterwaveGateway = $this->resolveFlutterwaveGateway();
        $verification = $flutterwaveGateway->verifyTransaction($transactionId);

        $payment = Payment::where('gateway_transaction_id', $transactionId)->first();

        if (! $payment && $txRef) {
            $payment = Payment::where('gateway_tx_ref', $txRef)
                ->where('status', PaymentStatus::Pending)
                ->first();
        }

        if (! $payment) {
            Log::warning('Flutterwave webhook: no payment matched charge', [
                'transaction_id' => $transactionId,
                'tx_ref' => $txRef,
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
            $webhookEvent->markProcessed();

            return;
        }

        if ($verification['status'] === 'successful') {
            $verifiedAmount = (float) $verification['amount'];
            $verifiedCurrency = $verification['currency'] ?? 'NGN';

            $amountMatches = (int) round((float) $payment->amount * 100) === (int) round($verifiedAmount * 100);

            if (! $amountMatches || strcasecmp($payment->currency ?? 'NGN', $verifiedCurrency) !== 0) {
                Log::warning('Flutterwave webhook: amount/currency mismatch', [
                    'payment_id' => $payment->id,
                    'expected_amount' => $payment->amount,
                    'verified_amount' => $verifiedAmount,
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

        $wasPending = $payment->status === PaymentStatus::Pending;

        $payment->update([
            'status' => $newStatus,
            'failure_reason' => $newStatus === PaymentStatus::Failed
                ? "Gateway status: {$verification['status']}"
                : null,
        ]);

        $payment->ride?->update(['payment_status' => $newStatus]);

        if ($wasPending && $newStatus === PaymentStatus::Captured && $payment->ride) {
            try {
                app(RideSettlementService::class)->applyPayment($payment->ride, $payment->fresh());
            } catch (\Throwable $e) {
                Log::error('Webhook: failed to settle driver earnings for captured payment', [
                    'payment_id' => $payment->id,
                    'ride_id' => $payment->ride_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
            Log::warning('Flutterwave webhook: transfer event missing transfer ID');
            $webhookEvent->markProcessed();

            return;
        }

        $reason = $data['complete_message'] ?? ($data['narration'] ?? null);

        ProcessPayoutWebhookJob::dispatch($transferId, $status, $reason, $data['reference'] ?? null);

        $webhookEvent->markProcessed();
    }

    private function resolveFlutterwaveGateway(): FlutterwavePaymentGateway
    {
        $secretKey = config('services.flutterwave.secret_key');

        return new FlutterwavePaymentGateway(
            $secretKey,
            config('services.flutterwave.base_url', 'https://api.flutterwave.com/v3'),
        );
    }
}
