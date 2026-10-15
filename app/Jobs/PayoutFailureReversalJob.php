<?php

namespace App\Jobs;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Services\PayoutLedgerService;
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

    public function handle(PayoutLedgerService $payoutLedger): void
    {
        $payout = Payout::find($this->payoutId);

        if (! $payout) {
            Log::warning("Payout {$this->payoutId} not found for reversal");

            return;
        }

        $reversal = $payoutLedger->reverseActiveDebit(
            $payout,
            "Payout failure reversal for payout {$this->payoutId}",
        );

        if (! $reversal) {
            Log::info("No active debit to reverse for payout {$this->payoutId}");
        }

        Payout::where('id', $this->payoutId)
            ->where('status', PayoutStatus::Failed)
            ->update(['status' => PayoutStatus::Reversed]);

        Log::info("Payout debit reversed for payout {$this->payoutId}");
    }
}
