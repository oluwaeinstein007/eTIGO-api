<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\WalletGateway;
use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\WalletTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitializePaymentRequest;
use App\Jobs\ProcessTopupWebhookJob;
use App\Models\AppSetting;
use App\Models\UserPaymentMethod;
use App\Models\WalletTransaction;
use App\Services\LedgerService;
use App\Services\SavedCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentMethodController extends Controller
{
    // The wallet gateway is injected per method: it needs Paystack configured,
    // which listing or removing saved cards does not.
    public function __construct(
        private LedgerService $ledgerService,
        private SavedCardService $savedCards,
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
                'provider' => $card->provider,
                'is_default' => $card->is_default,
            ]);
        }

        return response()->json(['payment_methods' => $methods]);
    }

    /**
     * Starts adding a card. The passenger pays a small amount by card, which
     * is a wallet top-up: it is credited to their wallet, and the card is
     * saved once the payment succeeds.
     */
    public function initialize(InitializePaymentRequest $request, WalletGateway $walletGateway): JsonResponse
    {
        $user = $request->user();
        $amountKobo = AppSetting::wallet('card_verification_amount');

        $account = $this->ledgerService->findOrCreateAccount(
            $user->getMorphClass(),
            $user->id,
            AccountType::PassengerWallet,
        );

        $txRef = 'TOPUP-'.Str::upper(Str::random(12));

        try {
            $result = $walletGateway->initializePayment([
                'tx_ref' => $txRef,
                'amount' => $amountKobo / 100,
                'currency' => 'NGN',
                'redirect_url' => $request->validated('redirect_url'),
                'customer' => [
                    'email' => $user->paymentEmail(),
                    'name' => "{$user->first_name} {$user->last_name}",
                ],
                'meta' => [
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'type' => 'card_verification',
                ],
                'channels' => $request->validated('channels', ['card']),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to initialize payment. Please try again.'], 502);
        }

        WalletTransaction::create([
            'account_id' => $account->id,
            'reference' => $txRef,
            'amount' => $amountKobo,
            'save_card' => true,
            'status' => WalletTransactionStatus::Pending,
        ]);

        return response()->json([
            'payment_link' => $result['link'],
            'tx_ref' => $txRef,
            'amount' => $amountKobo / 100,
            'currency' => 'NGN',
        ]);
    }

    /**
     * Confirms a checkout started with initialize; {transactionId} is its
     * tx_ref. A successful payment is credited to the wallet (the webhook
     * does the same, only once) and the card is saved.
     */
    public function verify(Request $request, WalletGateway $walletGateway, string $transactionId): JsonResponse
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
            $result = $walletGateway->verifyTransaction($transaction->reference);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to verify transaction.'], 502);
        }

        if (($result['tx_ref'] ?? null) !== $transaction->reference) {
            return response()->json(['message' => 'Invalid transaction reference.'], 422);
        }

        $successful = $result['status'] === 'successful';
        $card = null;

        if ($successful) {
            if ($transaction->status !== WalletTransactionStatus::Completed) {
                ProcessTopupWebhookJob::dispatch($transaction->account_id, (int) $result['amount'], $transaction->reference);
            }

            if ($transaction->save_card) {
                $card = $this->savedCards->saveFromTopup($user, $result);
            }
        }

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
