<?php

namespace App\Jobs;

use App\Models\Journal;
use App\Services\LedgerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PayoutFailureReversalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly string $payoutId) {}

    public function handle(LedgerService $ledgerService): void
    {
        $journal = Journal::where('idempotency_key', "payout-debit-{$this->payoutId}")->first();

        if (! $journal) {
            Log::warning("No debit journal found for payout {$this->payoutId}");

            return;
        }

        // Check if already reversed
        $reversalExists = Journal::where('idempotency_key', "REV-{$journal->reference}")->exists();
        if ($reversalExists) {
            Log::info("Payout debit already reversed for payout {$this->payoutId}");

            return;
        }

        $ledgerService->reverse($journal, "Payout failure reversal for payout {$this->payoutId}");

        Log::info("Payout debit reversed for payout {$this->payoutId}");
    }
}
