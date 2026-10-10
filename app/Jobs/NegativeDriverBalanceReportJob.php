<?php

namespace App\Jobs;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NegativeDriverBalanceReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $threshold = config('wallet.max_negative_balance', -500000);
        $report = collect();
        $criticalCount = 0;
        $totalBalance = 0;

        Account::where('type', AccountType::DriverEarningsAvailable)
            ->where('balance', '<', 0)
            ->with('owner')
            ->chunkById(100, function ($accounts) use ($threshold, $report, &$criticalCount, &$totalBalance) {
                foreach ($accounts as $account) {
                    $totalBalance += $account->balance;
                    if ($account->balance <= $threshold) {
                        $criticalCount++;
                    }
                    $report->push([
                        'driver_id' => $account->owner_id,
                        'balance_kobo' => $account->balance,
                        'balance_naira' => number_format($account->balance / 100, 2),
                        'exceeds_threshold' => $account->balance <= $threshold,
                    ]);
                }
            });

        if ($report->isEmpty()) {
            Log::info('NegativeDriverBalanceReport: no drivers with negative balance.');

            return;
        }

        Log::warning('NegativeDriverBalanceReport: weekly summary', [
            'total_negative_accounts' => $report->count(),
            'critical_accounts' => $criticalCount,
            'threshold_kobo' => $threshold,
            'total_negative_balance_kobo' => $totalBalance,
            'drivers' => $report->toArray(),
        ]);
    }
}
