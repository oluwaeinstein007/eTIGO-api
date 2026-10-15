<?php

namespace App\Jobs;

use App\Contracts\WalletGateway;
use App\Enums\NotificationType;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Notifications\PayoutStatusNotification;
use App\Services\PayoutLedgerService;
use App\Services\PayoutReconciliationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        $reference = $payout->gateway_reference ?? "PAYOUT-{$payout->id}";

        if ($payout->gateway_reference !== null) {
            // An earlier attempt used this reference, and Paystack treats a
            // reused reference as the same transfer. Find out what became of it
            // before sending: never pay twice, never resend a spent reference.
            $previous = $walletGateway->verifyTransfer($reference);
            $previousStatus = strtolower((string) ($previous['status'] ?? ''));

            if ($previousStatus === 'success') {
                ProcessPayoutWebhookJob::dispatchSync((string) $previous['id'], 'paid', null, $reference);

                return;
            }

            if ($previous !== null && ! in_array($previousStatus, PayoutReconciliationService::FAILED_STATUSES, true)) {
                $payout->update([
                    'status' => PayoutStatus::Processing,
                    'gateway_transfer_id' => (string) $previous['id'],
                    'provider_status' => $previousStatus,
                ]);
                PayoutReconciliationService::alertIfAwaitingOtp($payout);

                return;
            }

            if ($previous !== null) {
                $reference = $this->nextReference($payout, $reference);
            }
        }

        try {
            $recipientCode = $walletGateway->createTransferRecipient(
                $payout->bankAccount->account_number,
                $payout->bankAccount->bank_code,
            );

            // Saved before the transfer starts: Paystack asks our transfer
            // approval URL about it, and the approval checks the reference,
            // amount and recipient against this payout.
            $payout->update([
                'gateway_reference' => $reference,
                'gateway_recipient_code' => $recipientCode,
            ]);

            $transfer = $walletGateway->initiateTransfer([
                'recipient_code' => $recipientCode,
                'amount' => $payout->amount / 100,
                'narration' => "E-tiGo driver payout #{$payout->id}",
                'currency' => 'NGN',
                'reference' => $reference,
            ]);

            if (in_array(strtolower((string) ($transfer['status'] ?? '')), PayoutReconciliationService::FAILED_STATUSES, true)) {
                throw new \RuntimeException("Transfer {$transfer['status']} by the payment provider.");
            }

            $payout->update([
                'status' => PayoutStatus::Processing,
                'gateway_transfer_id' => (string) $transfer['id'],
                'provider_status' => strtolower((string) ($transfer['status'] ?? '')) ?: null,
            ]);

            Log::info("Payout transfer initiated: {$payout->id}, transfer ID: {$transfer['id']}");
            PayoutReconciliationService::alertIfAwaitingOtp($payout);
        } catch (\Throwable $e) {
            if (PayoutReconciliationService::isProviderUnavailable($e)) {
                // A timeout or 5xx may still have created the transfer: keep the
                // money reserved and let the retry verify this reference first.
                Log::error("Payout transfer outcome unknown: {$payout->id}", ['error' => $e->getMessage()]);

                throw $e;
            }

            $payoutLedger->reverseActiveDebit($payout, "Payout failed: reversal for payout {$payout->id}");
            $this->markFailed($payout, $e->getMessage());

            Log::error("Payout transfer failed: {$payout->id}", ['error' => $e->getMessage()]);
        }
    }

    /**
     * A new attempt after a definitive failure: PAYOUT-{id} → PAYOUT-{id}-2 → -3 …
     */
    private function nextReference(Payout $payout, string $previous): string
    {
        $suffix = Str::after($previous, "PAYOUT-{$payout->id}");
        $attempt = preg_match('/^-(\d+)$/', $suffix, $matches) ? (int) $matches[1] + 1 : 2;

        return "PAYOUT-{$payout->id}-{$attempt}";
    }

    /**
     * Every attempt failed to reach Paystack, so whether the transfer was
     * created is unknown. The money stays reserved and the payout stays
     * approved: ReconcilePayoutsJob (or finance's "check status" action) asks
     * Paystack about the reference and settles it either way.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::critical('Payout transfer could not be confirmed with Paystack; left for reconciliation', [
            'payout_id' => $this->payoutId,
            'error' => $exception?->getMessage(),
        ]);
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
