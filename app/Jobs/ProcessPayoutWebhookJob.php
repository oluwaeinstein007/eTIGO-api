<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Notifications\PayoutStatusNotification;
use App\Services\PayoutLedgerService;
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

    /**
     * Our PAYOUT-{id} transfer reference. Used when the provider's transfer id
     * was never recorded (the initiate call failed after the transfer was
     * created). Declared with a default so jobs queued before it existed
     * still unserialize.
     */
    public ?string $reference = null;

    public function __construct(
        public readonly string $transferCode,
        public readonly string $status,
        public readonly ?string $reason = null,
        ?string $reference = null,
    ) {
        $this->reference = $reference;
    }

    public function handle(PayoutLedgerService $payoutLedger): void
    {
        $payout = Payout::where('gateway_transfer_id', $this->transferCode)->first()
            ?? ($this->reference ? Payout::where('gateway_reference', $this->reference)->first() : null);

        if (! $payout) {
            Log::warning("Payout not found for transfer code: {$this->transferCode}");

            return;
        }

        if ($this->status === 'paid') {
            $this->markPaid($payout, $payoutLedger);

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

        $payout->update([
            'status' => PayoutStatus::Failed,
            'failure_reason' => $this->reason,
        ]);

        PayoutFailureReversalJob::dispatch($payout->id);

        rescue(fn () => $payout->driver?->user?->notify(new PayoutStatusNotification(
            $payout->amount,
            NotificationType::PayoutFailed,
            $this->reason,
        )));

        Log::warning("Payout {$payout->id} failed: {$this->reason}");
    }

    private function markPaid(Payout $payout, PayoutLedgerService $payoutLedger): void
    {
        if (in_array($payout->status, [PayoutStatus::Paid, PayoutStatus::Rejected], true)) {
            Log::info("Payout {$payout->id} already in terminal state: {$payout->status->value}");

            return;
        }

        if (in_array($payout->status, [PayoutStatus::Failed, PayoutStatus::Reversed], true)) {
            // The provider completed a transfer we had written off: the money
            // has left, so take it out of the driver's balance again even if
            // that leaves the balance negative.
            $payoutLedger->debit($payout, requireSufficientBalance: false);

            Log::critical("Payout {$payout->id} reported paid after being marked {$payout->status->value}; ledger re-debited.", [
                'transfer_code' => $this->transferCode,
            ]);
        }

        $payout->update([
            'status' => PayoutStatus::Paid,
            'paid_at' => now(),
            'gateway_transfer_id' => $payout->gateway_transfer_id ?? $this->transferCode,
        ]);

        rescue(fn () => $payout->driver?->user?->notify(new PayoutStatusNotification(
            $payout->amount,
            NotificationType::PayoutPaid,
        )));

        Log::info("Payout {$payout->id} marked as paid.");
    }
}
