<?php

namespace App\Jobs;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPayoutWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $transferCode,
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {}

    public function handle(): void
    {
        $payout = Payout::where('gateway_transfer_id', $this->transferCode)->first();

        if (! $payout) {
            Log::warning("Payout not found for transfer code: {$this->transferCode}");

            return;
        }

        if ($payout->status->isTerminal()) {
            Log::info("Payout {$payout->id} already in terminal state: {$payout->status->value}");

            return;
        }

        if ($payout->status === PayoutStatus::Failed) {
            Log::warning("Payout {$payout->id} is already failed, ignoring webhook status '{$this->status}'");

            return;
        }

        if ($this->status === 'paid') {
            $payout->update([
                'status' => PayoutStatus::Paid,
                'paid_at' => now(),
            ]);
            Log::info("Payout {$payout->id} marked as paid.");
        } else {
            $payout->update([
                'status' => PayoutStatus::Failed,
                'failure_reason' => $this->reason,
            ]);

            PayoutFailureReversalJob::dispatch($payout->id);

            Log::warning("Payout {$payout->id} failed: {$this->reason}");
        }
    }
}
