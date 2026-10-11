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
        $secretKey = config('wallet.paystack.secret_key');

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
        $amount = (int) ($data['amount'] ?? 0);
        $metadata = $data['metadata'] ?? [];
        $accountId = $metadata['account_id'] ?? null;
        $status = $data['status'] ?? '';

        if ($status === 'success' && $accountId && str_starts_with($reference, 'TOPUP-')) {
            ProcessTopupWebhookJob::dispatch($accountId, $amount, $reference);
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
