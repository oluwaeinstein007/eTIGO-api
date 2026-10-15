<?php

namespace App\Services;

use App\Contracts\WalletGateway;
use App\Enums\PayoutStatus;
use App\Jobs\ProcessPayoutTransferJob;
use App\Jobs\ProcessPayoutWebhookJob;
use App\Models\Payout;
use Illuminate\Support\Facades\Log;

/**
 * Settles payouts whose transfer outcome is unknown — the provider timed out,
 * was unreachable for every attempt, or its webhook never arrived — by asking
 * Paystack about the transfer reference, as Paystack recommends, instead of
 * guessing. Money stays reserved until the outcome is known.
 */
class PayoutReconciliationService
{
    /** Paystack transfer statuses after which the transfer will never pay out. */
    public const FAILED_STATUSES = ['failed', 'reversed', 'abandoned', 'rejected', 'blocked'];

    public const OUTCOME_PAID = 'paid';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_PROCESSING = 'processing';

    public const OUTCOME_RESENT = 'resent';

    public const OUTCOME_UNKNOWN = 'unknown';

    public const OUTCOME_UNCHANGED = 'unchanged';

    public function __construct(private WalletGateway $walletGateway) {}

    /**
     * @return array{outcome: string, provider_status: string|null}
     *
     * @throws \Throwable when Paystack cannot be reached (nothing is changed)
     */
    public function reconcile(Payout $payout): array
    {
        if (! in_array($payout->status, [PayoutStatus::Approved, PayoutStatus::Processing], true)) {
            return ['outcome' => self::OUTCOME_UNCHANGED, 'provider_status' => null];
        }

        if ($payout->gateway_reference === null) {
            // The transfer was never attempted (the job did not run or stopped early).
            ProcessPayoutTransferJob::dispatch($payout->id);

            return ['outcome' => self::OUTCOME_RESENT, 'provider_status' => null];
        }

        $transfer = $this->walletGateway->verifyTransfer($payout->gateway_reference);

        if ($transfer === null) {
            if ($payout->status === PayoutStatus::Approved) {
                // Paystack never created it, so sending again under the same
                // reference cannot pay twice.
                ProcessPayoutTransferJob::dispatch($payout->id);

                return ['outcome' => self::OUTCOME_RESENT, 'provider_status' => null];
            }

            Log::critical('Payout is processing but Paystack has no transfer with its reference', [
                'payout_id' => $payout->id,
                'reference' => $payout->gateway_reference,
            ]);

            return ['outcome' => self::OUTCOME_UNKNOWN, 'provider_status' => null];
        }

        $status = strtolower((string) $transfer['status']);

        if ($status === 'success') {
            ProcessPayoutWebhookJob::dispatchSync((string) $transfer['id'], 'paid', null, $payout->gateway_reference);

            return ['outcome' => self::OUTCOME_PAID, 'provider_status' => $status];
        }

        if (in_array($status, self::FAILED_STATUSES, true)) {
            ProcessPayoutWebhookJob::dispatchSync(
                (string) $transfer['id'],
                'failed',
                "Transfer {$status} by the payment provider.",
                $payout->gateway_reference,
            );

            return ['outcome' => self::OUTCOME_FAILED, 'provider_status' => $status];
        }

        // Still in flight (pending, otp, received, ...): wait for the webhook.
        if ($payout->status === PayoutStatus::Approved) {
            $payout->update([
                'status' => PayoutStatus::Processing,
                'gateway_transfer_id' => (string) $transfer['id'],
            ]);
        } else {
            $payout->touch();
        }

        return ['outcome' => self::OUTCOME_PROCESSING, 'provider_status' => $status];
    }
}
