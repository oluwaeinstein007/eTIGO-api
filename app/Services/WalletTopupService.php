<?php

namespace App\Services;

use App\Contracts\WalletGateway;
use App\Enums\AccountType;
use App\Enums\WalletTransactionStatus;
use App\Exceptions\TopupRefusedException;
use App\Jobs\ProcessTopupWebhookJob;
use App\Models\AppSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Starts and confirms passenger wallet top-ups: a plain top-up, and the
 * payment taken when a passenger adds a card, which is a top-up too. Both
 * get the same wallet checks and attempt limits, and are credited by
 * ProcessTopupWebhookJob (webhook, verify fallback or sweeper), once.
 */
class WalletTopupService
{
    public function __construct(private LedgerService $ledgerService) {}

    /**
     * @param  list<string>  $channels
     * @return array{transaction: WalletTransaction, link: string}
     *
     * @throws TopupRefusedException
     */
    public function start(
        User $user,
        int $amountKobo,
        string $callbackUrl,
        array $channels = ['card', 'bank_transfer', 'ussd'],
        bool $saveCard = false,
        string $type = 'wallet_topup',
    ): array {
        $account = $this->ledgerService->findOrCreateAccount(
            $user->getMorphClass(),
            $user->id,
            AccountType::PassengerWallet,
        );

        if (! $account->isActive()) {
            throw new TopupRefusedException('Wallet is frozen or closed.');
        }

        $maxBalance = AppSetting::wallet('max_balance');
        if (($account->balance + $amountKobo) > $maxBalance) {
            $maxNaira = $maxBalance / 100;

            throw new TopupRefusedException("Top-up would exceed maximum wallet balance of ₦{$maxNaira}.");
        }

        $dailyCap = AppSetting::wallet('daily_topup_cap');
        $todayTopups = Cache::get("topup_daily:{$user->id}:".now()->toDateString(), 0);
        if (($todayTopups + $amountKobo) > $dailyCap) {
            throw new TopupRefusedException('Daily top-up limit exceeded.');
        }

        $hourlyKey = "topup_hourly:{$user->id}:".now()->format('Y-m-d-H');
        Cache::add($hourlyKey, 0, now()->addHour());
        if (Cache::increment($hourlyKey) > config('wallet.max_topups_per_hour', 5)) {
            Cache::decrement($hourlyKey);

            throw new TopupRefusedException('Too many top-up attempts. Please wait.', 429);
        }

        $dailyCountKey = "topup_daily_count:{$user->id}:".now()->toDateString();
        Cache::add($dailyCountKey, 0, now()->endOfDay());
        if (Cache::increment($dailyCountKey) > config('wallet.max_topups_per_day', 10)) {
            Cache::decrement($dailyCountKey);
            Cache::decrement($hourlyKey);

            throw new TopupRefusedException('Daily top-up attempt limit reached.', 429);
        }

        $txRef = 'TOPUP-'.strtoupper(Str::random(12));

        try {
            $result = app(WalletGateway::class)->initializePayment([
                'tx_ref' => $txRef,
                'amount' => $amountKobo / 100,
                'currency' => 'NGN',
                'redirect_url' => $callbackUrl,
                'customer' => [
                    'email' => $user->paymentEmail(),
                    'name' => "{$user->first_name} {$user->last_name}",
                ],
                'meta' => [
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'type' => $type,
                ],
                'channels' => $channels,
            ]);
        } catch (\Throwable $e) {
            throw new TopupRefusedException('Unable to initialize payment. Please try again.', 502);
        }

        $transaction = WalletTransaction::create([
            'account_id' => $account->id,
            'reference' => $txRef,
            'amount' => $amountKobo,
            'save_card' => $saveCard,
            'status' => WalletTransactionStatus::Pending,
        ]);

        return ['transaction' => $transaction, 'link' => $result['link']];
    }

    /**
     * Asks Paystack about a top-up and, when it succeeded, queues the credit
     * of the amount Paystack collected. Returns Paystack's verification.
     *
     * @return array<string, mixed>
     *
     * @throws TopupRefusedException
     */
    public function confirm(WalletTransaction $transaction): array
    {
        try {
            $result = app(WalletGateway::class)->verifyTransaction($transaction->reference);
        } catch (\Throwable $e) {
            throw new TopupRefusedException('Unable to verify transaction.', 502);
        }

        if (($result['tx_ref'] ?? null) !== $transaction->reference) {
            throw new TopupRefusedException('Invalid transaction reference.');
        }

        if ($result['status'] === 'successful' && $transaction->status !== WalletTransactionStatus::Completed) {
            ProcessTopupWebhookJob::dispatch($transaction->account_id, (int) $result['amount'], $transaction->reference);
        }

        return $result;
    }
}
