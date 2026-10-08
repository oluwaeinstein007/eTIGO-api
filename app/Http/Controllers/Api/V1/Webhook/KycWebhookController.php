<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\KycVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KycWebhookController extends Controller
{
    public function __construct(
        private KycVerificationService $kycService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if ($request->isMethod('get')) {
            return response()->json(['message' => 'Webhook endpoint active.']);
        }

        $signature = $request->header('X-QoreID-Signature');
        $payload = $request->all();

        if (! $this->verifySignature($request->getContent(), $signature)) {
            Log::warning('KYC webhook: invalid signature', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        Log::info('KYC webhook received', [
            'event' => $payload['event'] ?? 'unknown',
            'session_id' => $payload['sessionId'] ?? $payload['data']['sessionId'] ?? null,
        ]);

        $event = $payload['event'] ?? null;
        $sessionId = $payload['sessionId']
            ?? $payload['data']['sessionId']
            ?? $payload['data']['session_id']
            ?? null;

        if (! $sessionId) {
            return response()->json(['message' => 'Missing session ID.'], 400);
        }

        if (in_array($event, ['verification_completed', 'step_verification_completed', 'identity'])) {
            $verification = $this->kycService->processLivenessResult($sessionId);

            if ($verification) {
                AuditLog::record($verification, 'kyc_webhook_processed');

                Log::info('KYC webhook processed', [
                    'verification_id' => $verification->id,
                    'status' => $verification->status->value,
                ]);
            }
        }

        return response()->json(['message' => 'Webhook processed.']);
    }

    private function verifySignature(?string $payload, ?string $signature): bool
    {
        $webhookSecret = config('services.qoreid.webhook_secret');

        if (! $webhookSecret || ! $signature || ! $payload) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($expected, $signature);
    }
}
