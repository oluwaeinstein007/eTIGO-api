<?php

namespace App\Jobs;

use App\Contracts\WalletGateway;
use App\Enums\NotificationType;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Notifications\PayoutStatusNotification;
use App\Services\PayoutLedgerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPayoutTransferJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $payoutId) {}

    public function uniqueId(): string
    {
        return $this->payoutId;
    }

    public function handle(WalletGateway $walletGateway, PayoutLedgerService $payoutLedger): void
    {
        $payout = Payout::with(['bankAccount', 'driver.user'])->findOrFail($this->payoutId);

        if (! $payout->isApproved()) {
            Log::warning("Payout {$this->payoutId} is not in approved status, skipping.");

            return;
        }

        // The money is reserved in the ledger before it leaves; a retried
        // payout whose earlier attempt was reversed is debited again.
        try {
            $payoutLedger->debit($payout);
        } catch (\DomainException $e) {
            $this->markFailed($payout, $e->getMessage());

            return;
        }

        $reference = "PAYOUT-{$payout->id}";

        $payout->update([
            'gateway_reference' => $reference,
        ]);

        try {
            $transfer = $walletGateway->initiateTransfer([
                'account_bank' => $payout->bankAccount->bank_code,
                'account_number' => $payout->bankAccount->account_number,
                'amount' => $payout->amount / 100,
                'narration' => "E-tiGo driver payout #{$payout->id}",
                'currency' => 'NGN',
                'reference' => $reference,
            ]);

            $payout->update([
                'status' => PayoutStatus::Processing,
                'gateway_transfer_id' => (string) $transfer['id'],
            ]);

            Log::info("Payout transfer initiated: {$payout->id}, transfer ID: {$transfer['id']}");
        } catch (ConnectionException $e) {
            Log::error("Payout transfer network error: {$payout->id}", ['error' => $e->getMessage()]);

            throw $e;
        } catch (\Throwable $e) {
            $payoutLedger->reverseActiveDebit($payout, "Payout failed: reversal for payout {$payout->id}");
            $this->markFailed($payout, $e->getMessage());

            Log::error("Payout transfer failed: {$payout->id}", ['error' => $e->getMessage()]);
        }
    }

    private function markFailed(Payout $payout, string $reason): void
    {
        $payout->update([
            'status' => PayoutStatus::Failed,
            'failure_reason' => $reason,
        ]);

        rescue(fn () => $payout->driver?->user?->notify(new PayoutStatusNotification(
            $payout->amount,
            NotificationType::PayoutFailed,
            $reason,
        )));
    }
}
