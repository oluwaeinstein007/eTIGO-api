<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
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

        if ($payment) {
            $payment->update([
                'status' => $verification['status'] === 'successful' ? 'captured' : 'failed',
                'failure_reason' => $verification['status'] !== 'successful' ? "Gateway status: {$verification['status']}" : null,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}
