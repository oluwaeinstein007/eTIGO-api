<?php

namespace App\Jobs;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Services\LedgerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        try {
            $ledgerService->postJournal([
                ['account_id' => $pspClearingAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $this->amountKobo],
                ['account_id' => $this->accountId, 'type' => LedgerEntryType::Credit->value, 'amount' => $this->amountKobo],
            ], [
                'description' => "Wallet top-up via Paystack: {$this->reference}",
                'idempotency_key' => "topup-{$this->reference}",
                'metadata' => [
                    'reference' => $this->reference,
                    'amount_kobo' => $this->amountKobo,
                ],
            ]);

            Log::info("Wallet top-up processed: {$this->reference}, amount: {$this->amountKobo}");
        } catch (\DomainException $e) {
            if (str_contains($e->getMessage(), 'Journal does not balance') || str_contains($e->getMessage(), 'not found')) {
                Log::error("Top-up processing failed: {$e->getMessage()}", [
                    'reference' => $this->reference,
                ]);
                throw $e;
            }
            // Idempotent — already posted
            Log::info("Top-up already processed: {$this->reference}");
        }
    }
}
