<?php

namespace App\Jobs;

use App\Contracts\WalletGateway;
use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\WalletTransactionStatus;
use App\Models\WalletTransaction;
use App\Services\LedgerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StuckTransactionSweeperJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(WalletGateway $gateway, LedgerService $ledgerService): void
    {
        $threshold = now()->subMinutes(config('wallet.abandoned_topup_minutes', 30));

        $stuckTransactions = WalletTransaction::where('status', WalletTransactionStatus::Pending)
            ->where('created_at', '<', $threshold)
            ->limit(50)
            ->get();

        if ($stuckTransactions->isEmpty()) {
            return;
        }

        Log::info("StuckTransactionSweeper: found {$stuckTransactions->count()} stuck transactions to verify.");

        foreach ($stuckTransactions as $transaction) {
            if (! $transaction->gateway_transaction_id) {
                continue;
            }

            try {
                $result = $gateway->verifyTransaction($transaction->gateway_transaction_id);
            } catch (\Throwable $e) {
                $transaction->update([
                    'status' => WalletTransactionStatus::Abandoned,
                    'abandoned_at' => now(),
                ]);
                Log::warning("StuckTransactionSweeper: could not verify transaction {$transaction->id}, marked abandoned.", [
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($result['status'] === 'successful') {
                try {
                    $this->creditWallet($transaction, $ledgerService);
                    Log::info("StuckTransactionSweeper: late-verified transaction {$transaction->id} — wallet credited.");
                } catch (\Throwable $e) {
                    Log::error("StuckTransactionSweeper: verified transaction {$transaction->id} but credit failed — left pending for retry.", [
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

    private function creditWallet(WalletTransaction $transaction, LedgerService $ledgerService): void
    {
        $idempotencyKey = "topup-{$transaction->reference}";

        $passengerAccount = $transaction->account;
        $pspAccount = $ledgerService->systemAccount(AccountType::PspClearing);

        $journal = $ledgerService->postJournal([
            ['account_id' => $pspAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $transaction->amount],
            ['account_id' => $passengerAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $transaction->amount],
        ], [
            'description' => "Wallet top-up (swept): {$transaction->reference}",
            'idempotency_key' => $idempotencyKey,
        ]);

        $transaction->update([
            'status' => WalletTransactionStatus::Completed,
            'journal_id' => $journal->id,
            'completed_at' => now(),
            'abandoned_at' => null,
        ]);
    }
}
