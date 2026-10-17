<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class SavedCardService
{
    /**
     * Saves the card a save_card top-up was paid with, from the wallet
     * gateway's verification of that top-up. Each top-up saves its card at
     * most once: later calls return the card saved the first time, or null
     * once the passenger has removed it. The top-up row is locked, so the
     * verify endpoint and SaveTopupCardJob cannot both save it.
     *
     * Returns null when the top-up was not paid by a card Paystack lets us
     * charge again (a bank transfer, USSD, or an authorization that is not
     * reusable).
     *
     * @param  array<string, mixed>  $verification
     */
    public function saveForTopup(WalletTransaction $transaction, array $verification): ?UserPaymentMethod
    {
        if (! $this->isReusableCard($verification)) {
            return null;
        }

        return DB::transaction(function () use ($transaction, $verification) {
            $transaction = WalletTransaction::with('account')->lockForUpdate()->find($transaction->id);

            if (! $transaction?->save_card || $transaction->account?->owner_type !== 'App\\Models\\User') {
                return null;
            }

            if ($transaction->card_saved_at) {
                return $transaction->paymentMethod;
            }

            $user = User::find($transaction->account->owner_id);

            if (! $user) {
                return null;
            }

            $card = $this->saveCard($user, $verification);

            $transaction->update([
                'payment_method_id' => $card->id,
                'card_saved_at' => now(),
            ]);

            return $card;
        });
    }

    /**
     * @param  array<string, mixed>  $verification
     */
    private function isReusableCard(array $verification): bool
    {
        return ($verification['status'] ?? null) === 'successful'
            && ($verification['channel'] ?? null) === 'card'
            && ! empty($verification['reusable'])
            && ! empty($verification['authorization_code'])
            && ! empty($verification['card_last_four']);
    }

    /**
     * Adds the card, or refreshes the passenger's saved copy of it. A card is
     * recognised by Paystack's signature, so a different card with the same
     * last four digits is saved separately. A card saved before signatures
     * were recorded is matched by last four digits and brand (Paystack's brand
     * can carry stray spaces) and gets the signature.
     *
     * @param  array<string, mixed>  $verification
     */
    private function saveCard(User $user, array $verification): UserPaymentMethod
    {
        $signature = $verification['card_signature'] ?? null;
        $brand = isset($verification['card_brand']) ? trim((string) $verification['card_brand']) : null;

        $attributes = [
            'provider' => 'paystack',
            'gateway_token' => $verification['authorization_code'],
            'gateway_signature' => $signature,
            // Charging the authorization later needs the email it was
            // issued under, which may no longer be the passenger's.
            'gateway_email' => $verification['customer_email'] ?? $user->paymentEmail(),
            'card_brand' => $brand,
            'card_last_four' => $verification['card_last_four'],
            'card_expiry_month' => $verification['card_expiry_month'] ?? null,
            'card_expiry_year' => $verification['card_expiry_year'] ?? null,
        ];

        $card = $signature
            ? UserPaymentMethod::where('user_id', $user->id)->where('gateway_signature', $signature)->first()
            : null;

        $card ??= UserPaymentMethod::where('user_id', $user->id)
            ->whereNull('gateway_signature')
            ->where('card_last_four', $verification['card_last_four'])
            ->whereRaw('LOWER(TRIM(card_brand)) = ?', [strtolower((string) $brand)])
            ->first();

        if ($card) {
            $card->update($attributes);

            return $card;
        }

        return UserPaymentMethod::create([
            ...$attributes,
            'user_id' => $user->id,
            'is_default' => ! UserPaymentMethod::where('user_id', $user->id)->exists(),
        ]);
    }
}
