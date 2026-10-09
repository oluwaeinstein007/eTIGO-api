<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitializePaymentRequest;
use App\Models\UserPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PaymentMethodController extends Controller
{
    public function __construct(
        private PaymentGateway $paymentGateway,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $savedCards = UserPaymentMethod::where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->get();

        $defaultIsCard = $savedCards->contains('is_default', true);

        $methods = collect([
            [
                'id' => PaymentMethod::Cash->value,
                'type' => PaymentMethod::Cash->value,
                'label' => PaymentMethod::Cash->label(),
                'is_default' => ! $defaultIsCard,
            ],
        ]);

        foreach ($savedCards as $card) {
            $methods->push([
                'id' => $card->id,
                'type' => PaymentMethod::Card->value,
                'label' => $card->card_brand
                    ? ucfirst($card->card_brand).' •••• '.$card->card_last_four
                    : 'Card •••• '.$card->card_last_four,
                'card_brand' => $card->card_brand,
                'card_last_four' => $card->card_last_four,
                'is_default' => $card->is_default,
            ]);
        }

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

        Cache::put("payment_tx_ref:{$txRef}", $user->id, now()->addHours(24));

        return response()->json($result);
    }

    public function verify(Request $request, string $transactionId): JsonResponse
    {
        $result = $this->paymentGateway->verifyTransaction($transactionId);

        $txRef = $result['tx_ref'] ?? null;
        $cacheKey = $txRef ? "payment_tx_ref:{$txRef}" : null;
        $ownerUserId = $cacheKey ? Cache::get($cacheKey) : null;

        if (! $ownerUserId || $ownerUserId !== $request->user()->id) {
            abort(403, 'Transaction does not belong to this user.');
        }

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

            Cache::forget($cacheKey);
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
