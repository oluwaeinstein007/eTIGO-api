<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPayoutWebhookJob;
use App\Jobs\ProcessTopupWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = config('wallet.paystack.webhook_secret');

        if ($secret) {
            $signature = $request->header('x-paystack-signature');
            $computed = hash_hmac('sha512', $request->getContent(), $secret);

            if (! hash_equals($computed, $signature ?? '')) {
                Log::warning('Paystack webhook: invalid signature');

                return response()->json(['message' => 'Invalid signature.'], 403);
            }
        }

        $payload = $request->all();
        $eventType = $payload['event'] ?? 'unknown';
        $eventId = data_get($payload, 'data.id', data_get($payload, 'data.reference', ''));

        // Deduplicate via webhook_events table
        $existing = WebhookEvent::where('provider', 'paystack')
            ->where('event_id', (string) $eventId)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Already processed.']);
        }

        $webhookEvent = WebhookEvent::create([
            'provider' => 'paystack',
            'event_id' => (string) $eventId,
            'event_type' => $eventType,
            'payload' => $payload,
            'signature' => $request->header('x-paystack-signature'),
            'created_at' => now(),
        ]);

        // Route to appropriate job
        match ($eventType) {
            'charge.success' => $this->handleChargeSuccess($payload, $webhookEvent),
            'transfer.success', 'transfer.failed' => $this->handleTransferEvent($payload, $webhookEvent),
            default => Log::info("Paystack webhook: unhandled event type {$eventType}"),
        };

        return response()->json(['message' => 'Webhook received.']);
    }

    private function handleChargeSuccess(array $payload, WebhookEvent $webhookEvent): void
    {
        $reference = data_get($payload, 'data.reference');
        $amount = (int) data_get($payload, 'data.amount', 0);
        $metadata = data_get($payload, 'data.metadata', []);
        $accountId = $metadata['account_id'] ?? null;

        if ($accountId && str_starts_with($reference, 'TOPUP-')) {
            ProcessTopupWebhookJob::dispatch($accountId, $amount, $reference);
        }

        $webhookEvent->markProcessed();
    }

    private function handleTransferEvent(array $payload, WebhookEvent $webhookEvent): void
    {
        $transferCode = data_get($payload, 'data.transfer_code');
        $status = data_get($payload, 'event') === 'transfer.success' ? 'paid' : 'failed';
        $reason = data_get($payload, 'data.reason');

        ProcessPayoutWebhookJob::dispatch($transferCode, $status, $reason);

        $webhookEvent->markProcessed();
    }
}
