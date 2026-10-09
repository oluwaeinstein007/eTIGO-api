<?php

namespace App\Jobs;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\WalletTransactionStatus;
use App\Models\Account;
use App\Models\WalletTransaction;
use App\Services\LedgerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessTopupWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $accountId,
        public readonly int $amountKobo,
        public readonly string $reference,
    ) {}

    public function uniqueId(): string
    {
        return $this->reference;
    }

    public function handle(LedgerService $ledgerService): void
    {
        $pspClearingAccount = $ledgerService->systemAccount(AccountType::PspClearing);

        $journal = $ledgerService->postJournal([
            ['account_id' => $pspClearingAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $this->amountKobo],
            ['account_id' => $this->accountId, 'type' => LedgerEntryType::Credit->value, 'amount' => $this->amountKobo],
        ], [
            'description' => "Wallet top-up via Flutterwave: {$this->reference}",
            'idempotency_key' => "topup-{$this->reference}",
            'metadata' => [
                'reference' => $this->reference,
                'amount_kobo' => $this->amountKobo,
            ],
        ]);

        if ($journal->wasRecentlyCreated) {
            $account = Account::find($this->accountId);
            if ($account && $account->owner_id) {
                $dailyKey = "topup_daily:{$account->owner_id}:".now()->toDateString();
                Cache::increment($dailyKey, $this->amountKobo);
                Cache::put($dailyKey, Cache::get($dailyKey, $this->amountKobo), now()->endOfDay());
            }

            WalletTransaction::where('reference', $this->reference)
                ->whereIn('status', [WalletTransactionStatus::Pending, WalletTransactionStatus::Abandoned])
                ->update([
                    'status' => WalletTransactionStatus::Completed,
                    'journal_id' => $journal->id,
                    'completed_at' => now(),
                    'abandoned_at' => null,
                ]);

            Log::info("Wallet top-up processed: {$this->reference}, amount: {$this->amountKobo}");
        } else {
            Log::info("Top-up already processed: {$this->reference}");
        }
    }
}
