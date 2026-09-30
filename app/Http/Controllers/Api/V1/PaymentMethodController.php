<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitializePaymentRequest;
use App\Models\UserPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentMethodController extends Controller
{
    public function __construct(
        private PaymentGateway $paymentGateway,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $methods = UserPaymentMethod::where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->get();

        return response()->json(['payment_methods' => $methods]);
    }

    public function initialize(InitializePaymentRequest $request): JsonResponse
    {
        $user = $request->user();
        $txRef = 'ETIGO-'.Str::upper(Str::random(12));

        $result = $this->paymentGateway->initializePayment([
            'tx_ref' => $txRef,
            'amount' => $request->validated('amount'),
            'currency' => $request->validated('currency'),
            'redirect_url' => $request->validated('redirect_url'),
            'customer_email' => $user->email,
            'customer_name' => "{$user->first_name} {$user->last_name}",
        ]);

        return response()->json($result);
    }

    public function verify(Request $request, string $transactionId): JsonResponse
    {
        $result = $this->paymentGateway->verifyTransaction($transactionId);

        if ($result['status'] === 'successful' && $result['card_last_four']) {
            UserPaymentMethod::firstOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'card_last_four' => $result['card_last_four'],
                    'card_brand' => $result['card_brand'],
                ],
                [
                    'gateway_token' => $result['tx_ref'],
                    'is_default' => ! UserPaymentMethod::where('user_id', $request->user()->id)->exists(),
                ],
            );
        }

        return response()->json($result);
    }

    public function setDefault(Request $request, UserPaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->user_id !== $request->user()->id) {
            abort(403);
        }

        UserPaymentMethod::where('user_id', $request->user()->id)
            ->update(['is_default' => false]);

        $paymentMethod->update(['is_default' => true]);

        return response()->json(['message' => 'Default payment method updated.']);
    }

    public function destroy(Request $request, UserPaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->user_id !== $request->user()->id) {
            abort(403);
        }

        $paymentMethod->delete();

        return response()->json(['message' => 'Payment method removed.']);
    }
}
