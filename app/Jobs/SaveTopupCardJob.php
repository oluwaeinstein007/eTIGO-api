<?php

namespace App\Jobs;

use App\Contracts\WalletGateway;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\SavedCardService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Saves the card a credited top-up was paid with, when the passenger asked
 * for it (adding a card, or a top-up with save_card). Paystack is asked for
 * the card details, so a webhook, the verify fallback and the stuck
 * transaction sweeper all end up here.
 */
class SaveTopupCardJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $reference) {}

    public function uniqueId(): string
    {
        return $this->reference;
    }

    public function handle(WalletGateway $gateway, SavedCardService $savedCards): void
    {
        $transaction = WalletTransaction::with('account')->where('reference', $this->reference)->first();

        if (! $transaction?->save_card || $transaction->account?->owner_type !== 'App\\Models\\User') {
            return;
        }

        $user = User::find($transaction->account->owner_id);

        if (! $user) {
            return;
        }

        $verification = $gateway->verifyTransaction($this->reference);

        if (($verification['tx_ref'] ?? null) !== $this->reference) {
            return;
        }

        $savedCards->saveFromTopup($user, $verification);
    }
}
