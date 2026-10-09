<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPayoutWebhookJob;
use App\Jobs\ProcessTopupWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FlutterwaveWalletWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $webhookHash = config('wallet.flutterwave.webhook_hash');

        if ($webhookHash) {
            $verifHash = $request->header('verif-hash');

            if (! hash_equals($webhookHash, (string) $verifHash)) {
                Log::warning('Flutterwave wallet webhook: invalid verif-hash');

                return response()->json(['message' => 'Invalid signature.'], 403);
            }
        }

        $payload = $request->all();
        $eventType = $payload['event'] ?? 'unknown';
        $data = $payload['data'] ?? [];
        $eventId = (string) ($data['id'] ?? $data['tx_ref'] ?? '');

        $existing = WebhookEvent::where('provider', 'flutterwave')
            ->where('event_id', $eventId)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Already processed.']);
        }

        $webhookEvent = WebhookEvent::create([
            'provider' => 'flutterwave',
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => $payload,
            'signature' => $request->header('verif-hash'),
            'created_at' => now(),
        ]);

        match ($eventType) {
            'charge.completed' => $this->handleChargeCompleted($data, $webhookEvent),
            'transfer.completed', 'transfer.failed' => $this->handleTransferEvent($payload, $webhookEvent),
            default => Log::info("Flutterwave wallet webhook: unhandled event type {$eventType}"),
        };

        return response()->json(['message' => 'Webhook received.']);
    }

    private function handleChargeCompleted(array $data, WebhookEvent $webhookEvent): void
    {
        $txRef = $data['tx_ref'] ?? '';
        $amount = (int) (($data['amount'] ?? 0) * 100);
        $meta = $data['meta'] ?? [];
        $accountId = $meta['account_id'] ?? null;
        $status = $data['status'] ?? '';

        if ($status === 'successful' && $accountId && str_starts_with($txRef, 'TOPUP-')) {
            ProcessTopupWebhookJob::dispatch($accountId, $amount, $txRef);
        }

        $webhookEvent->markProcessed();
    }

    private function handleTransferEvent(array $payload, WebhookEvent $webhookEvent): void
    {
        $data = $payload['data'] ?? [];
        $transferId = (string) ($data['id'] ?? '');
        $eventType = $payload['event'] ?? '';
        $status = $eventType === 'transfer.completed' ? 'paid' : 'failed';
        $reason = $data['complete_message'] ?? ($data['narration'] ?? null);

        ProcessPayoutWebhookJob::dispatch($transferId, $status, $reason);

        $webhookEvent->markProcessed();
    }
}
