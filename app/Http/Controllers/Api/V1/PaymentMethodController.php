<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Exceptions\TopupRefusedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitializePaymentRequest;
use App\Models\AppSetting;
use App\Models\UserPaymentMethod;
use App\Models\WalletTransaction;
use App\Services\SavedCardService;
use App\Services\WalletTopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function __construct(
        private WalletTopupService $topups,
        private SavedCardService $savedCards,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $savedCards = UserPaymentMethod::where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->get();

        $defaultIsCard = $savedCards->contains('is_default', true);

        // Cash is listed only while cash payments are switched on.
        $methods = collect(AppSetting::cashPaymentsEnabled() ? [
            [
                'id' => PaymentMethod::Cash->value,
                'type' => PaymentMethod::Cash->value,
                'label' => PaymentMethod::Cash->label(),
                'is_default' => ! $defaultIsCard,
            ],
        ] : []);

        foreach ($savedCards as $card) {
            $methods->push([
                'id' => $card->id,
                'type' => PaymentMethod::Card->value,
                'label' => $card->card_brand
                    ? ucfirst($card->card_brand).' •••• '.$card->card_last_four
                    : 'Card •••• '.$card->card_last_four,
                'card_brand' => $card->card_brand,
                'card_last_four' => $card->card_last_four,
                'provider' => $card->provider,
                'is_default' => $card->is_default,
            ]);
        }

        return response()->json(['payment_methods' => $methods]);
    }

    /**
     * Starts adding a card. The passenger pays a small amount by card, which
     * is a wallet top-up with the usual wallet checks and limits: it is
     * credited to their wallet, and the card is saved once the payment
     * succeeds.
     */
    public function initialize(InitializePaymentRequest $request): JsonResponse
    {
        $amountKobo = AppSetting::wallet('card_verification_amount');

        try {
            $started = $this->topups->start(
                $request->user(),
                $amountKobo,
                $request->validated('redirect_url'),
                channels: ['card'],
                saveCard: true,
                type: 'card_verification',
            );
        } catch (TopupRefusedException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'payment_link' => $started['link'],
            'tx_ref' => $started['transaction']->reference,
            'amount' => $amountKobo / 100,
            'currency' => 'NGN',
        ]);
    }

    /**
     * Confirms a checkout started with initialize; {transactionId} is its
     * tx_ref. A successful payment is credited to the wallet (the webhook
     * does the same, only once) and the card is saved.
     */
    public function verify(Request $request, string $transactionId): JsonResponse
    {
        $user = $request->user();

        $transaction = WalletTransaction::where('reference', $transactionId)
            ->whereHas('account', fn ($query) => $query
                ->where('owner_type', $user->getMorphClass())
                ->where('owner_id', $user->id))
            ->first();

        if (! $transaction) {
            abort(403, 'Transaction does not belong to this user.');
        }

        try {
            $result = $this->topups->confirm($transaction);
        } catch (TopupRefusedException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $successful = $result['status'] === 'successful';
        $card = $successful ? $this->savedCards->saveForTopup($transaction, $result) : null;

        return response()->json([
            'status' => $result['status'],
            'tx_ref' => $transaction->reference,
            'amount' => $result['amount'] / 100,
            'currency' => $result['currency'] ?? 'NGN',
            'payment_type' => $result['channel'] ?? null,
            'card_last_four' => $result['card_last_four'] ?? null,
            'card_brand' => $result['card_brand'] ?? null,
            'card_saved' => $card !== null,
            'payment_method_id' => $card?->id,
            'wallet_credit' => $successful ? $result['amount'] / 100 : 0,
        ]);
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
