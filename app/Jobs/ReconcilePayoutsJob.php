<?php

namespace App\Jobs;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Services\PayoutReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Finds payouts whose transfer outcome was never confirmed — approved but not
 * sent (Paystack unreachable), or processing with no webhook — and settles
 * them against Paystack's record of the transfer reference.
 */
class ReconcilePayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BATCH_SIZE = 50;

    public function handle(PayoutReconciliationService $reconciler): void
    {
        $stale = Payout::query()
            ->where(fn ($query) => $query
                ->where('status', PayoutStatus::Approved)
                ->where('approved_at', '<=', now()->subMinutes(Payout::APPROVED_STALE_MINUTES)))
            ->orWhere(fn ($query) => $query
                ->where('status', PayoutStatus::Processing)
                ->where('updated_at', '<=', now()->subMinutes(Payout::PROCESSING_STALE_MINUTES)))
            ->orderBy('updated_at')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($stale as $payout) {
            try {
                $result = $reconciler->reconcile($payout);

                Log::info('Payout reconciled with Paystack', ['payout_id' => $payout->id] + $result);
            } catch (\Throwable $e) {
                // Paystack is still unreachable: nothing changed, try on the next run.
                Log::warning('Payout reconciliation deferred', [
                    'payout_id' => $payout->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
