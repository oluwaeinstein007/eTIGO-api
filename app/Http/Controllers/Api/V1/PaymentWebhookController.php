<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Events\PaymentUpdated;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentGateway $paymentGateway,
    ) {}

    public function handleFlutterwave(Request $request): JsonResponse
    {
        $secretHash = config('services.flutterwave.encryption_key');

        if (! $secretHash) {
            Log::error('Flutterwave webhook: encryption key not configured');

            return response()->json(['status' => 'error'], 500);
        }

        if (! hash_equals($secretHash, (string) $request->header('verif-hash'))) {
            Log::warning('Flutterwave webhook: invalid signature');

            return response()->json(['status' => 'error'], 401);
        }

        $payload = $request->input('data');
        $transactionId = (string) ($payload['id'] ?? '');

        if (! $transactionId) {
            return response()->json(['status' => 'ignored']);
        }

        $verification = $this->paymentGateway->verifyTransaction($transactionId);

        $payment = Payment::where('gateway_transaction_id', $transactionId)->first();

        if (! $payment) {
            // Webhook arrived before ProcessPaymentJob — look up by pending status
            // so the webhook can resolve payments left pending after gateway timeouts.
            $payment = Payment::where('status', PaymentStatus::Pending)
                ->whereHas('ride', fn ($q) => $q->where('payment_method', 'card'))
                ->latest()
                ->first();

            if ($payment) {
                $payment->update(['gateway_transaction_id' => $transactionId]);
            }
        }

        if ($payment) {
            $terminalStatuses = [
                PaymentStatus::Refunded,
                PaymentStatus::Collected,
                PaymentStatus::Settled,
            ];

            if (in_array($payment->status, $terminalStatuses)) {
                Log::info('Flutterwave webhook: skipping update for terminal payment', [
                    'payment_id' => $payment->id,
                    'current_status' => $payment->status->value,
                ]);

                return response()->json(['status' => 'ok']);
            }

            $newStatus = $verification['status'] === 'successful'
                ? PaymentStatus::Captured
                : PaymentStatus::Failed;

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
        }

        return response()->json(['status' => 'ok']);
    }
}
