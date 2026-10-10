<?php

namespace App\Jobs;

use App\Enums\WalletTransactionStatus;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireAbandonedTopupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $threshold = now()->subMinutes(config('wallet.abandoned_topup_minutes', 30));

        $pending = WalletTransaction::where('status', WalletTransactionStatus::Pending)
            ->where('created_at', '<', $threshold)
            ->get();

        $count = 0;

        foreach ($pending as $transaction) {
            DB::transaction(function () use ($transaction, &$count) {
                $transaction = WalletTransaction::whereKey($transaction->id)
                    ->lockForUpdate()
                    ->first();

                if ($transaction && $transaction->isPending()) {
                    $transaction->update([
                        'status' => WalletTransactionStatus::Abandoned,
                        'abandoned_at' => now(),
                    ]);
                    $count++;
                }
            });
        }

        if ($count > 0) {
            Log::info("Marked {$count} abandoned top-up transactions.");
        }
    }
}
