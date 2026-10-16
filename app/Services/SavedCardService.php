<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPaymentMethod;
use Illuminate\Support\Facades\Cache;

class SavedCardService
{
    /**
     * Saves the card a wallet top-up was paid with, from the wallet gateway's
     * verification of that top-up. A card the passenger already saved gets
     * the new authorization. Returns null when the top-up was not paid by a
     * card Paystack lets us charge again (a bank transfer, USSD, or a card
     * whose authorization is not reusable).
     *
     * @param  array<string, mixed>  $verification
     */
    public function saveFromTopup(User $user, array $verification): ?UserPaymentMethod
    {
        if (($verification['status'] ?? null) !== 'successful'
            || ($verification['channel'] ?? null) !== 'card'
            || empty($verification['reusable'])
            || empty($verification['authorization_code'])
            || empty($verification['card_last_four'])) {
            return null;
        }

        // The verify endpoint and SaveTopupCardJob can save the same card at
        // the same time.
        return Cache::lock("saved-card:{$user->id}", 10)->block(5, function () use ($user, $verification) {
            $attributes = [
                'provider' => 'paystack',
                'gateway_token' => $verification['authorization_code'],
                'gateway_email' => $user->paymentEmail(),
                'card_expiry_month' => $verification['card_expiry_month'] ?? null,
                'card_expiry_year' => $verification['card_expiry_year'] ?? null,
            ];

            $card = UserPaymentMethod::where('user_id', $user->id)
                ->where('card_last_four', $verification['card_last_four'])
                ->where('card_brand', $verification['card_brand'] ?? null)
                ->first();

            if ($card) {
                $card->update($attributes);

                return $card;
            }

            return UserPaymentMethod::create([
                ...$attributes,
                'user_id' => $user->id,
                'card_last_four' => $verification['card_last_four'],
                'card_brand' => $verification['card_brand'] ?? null,
                'is_default' => ! UserPaymentMethod::where('user_id', $user->id)->exists(),
            ]);
        });
    }
}
