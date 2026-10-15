<?php

namespace App\Jobs;

use App\Contracts\WalletGateway;
use App\Enums\WalletTransactionStatus;
use App\Models\AppSetting;
use App\Models\WalletTransaction;
use App\Services\LedgerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Recovers top-ups whose webhook never arrived. Pending top-ups past the
 * abandonment threshold — and ones already marked abandoned within the last
 * two days, since the passenger may still have paid — are verified with the
 * provider by their reference and credited when the payment succeeded.
 */
class StuckTransactionSweeperJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    private const RECHECK_WINDOW_HOURS = 48;

    private const BATCH_SIZE = 50;

    public function handle(WalletGateway $gateway, LedgerService $ledgerService): void
    {
        $threshold = now()->subMinutes(AppSetting::wallet('abandoned_topup_minutes'));

        $stuckTransactions = WalletTransaction::whereIn('status', [
            WalletTransactionStatus::Pending,
            WalletTransactionStatus::Abandoned,
        ])
            ->where('created_at', '<', $threshold)
            ->where('created_at', '>', now()->subHours(self::RECHECK_WINDOW_HOURS))
            ->orderBy('updated_at')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($stuckTransactions->isEmpty()) {
            return;
        }

        Log::info("StuckTransactionSweeper: found {$stuckTransactions->count()} stuck transactions to verify.");

        foreach ($stuckTransactions as $transaction) {
            try {
                $result = $gateway->verifyTransaction($transaction->gateway_transaction_id ?? $transaction->reference);
            } catch (\Throwable $e) {
                $transaction->update([
                    'status' => WalletTransactionStatus::Abandoned,
                    'abandoned_at' => $transaction->abandoned_at ?? now(),
                ]);
                $transaction->touch();
                Log::warning("StuckTransactionSweeper: could not verify transaction {$transaction->id}, marked abandoned.", [
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($result['status'] === 'successful') {
                try {
                    // Same path as a webhook: idempotent credit, notification,
                    // daily-cap accounting and transaction status update.
                    (new ProcessTopupWebhookJob(
                        $transaction->account_id,
                        (int) ($result['amount'] ?? $transaction->amount),
                        $transaction->reference,
                    ))->handle($ledgerService);

                    Log::info("StuckTransactionSweeper: late-verified transaction {$transaction->id} — wallet credited.");
                } catch (\Throwable $e) {
                    $transaction->touch();
                    Log::error("StuckTransactionSweeper: verified transaction {$transaction->id} but credit failed — left for retry.", [
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                $transaction->update([
                    'status' => WalletTransactionStatus::Failed,
                ]);
                Log::info("StuckTransactionSweeper: transaction {$transaction->id} verified as {$result['status']} — marked failed.");
            }
        }
    }
}
