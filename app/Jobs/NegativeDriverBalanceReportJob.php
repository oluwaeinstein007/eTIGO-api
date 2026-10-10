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
        $negativeAccounts = Account::where('type', AccountType::DriverEarningsAvailable)
            ->where('balance', '<', 0)
            ->with('owner')
            ->get();

        if ($negativeAccounts->isEmpty()) {
            Log::info('NegativeDriverBalanceReport: no drivers with negative balance.');

            return;
        }

        $threshold = config('wallet.max_negative_balance', -500000);
        $critical = $negativeAccounts->filter(fn ($account) => $account->balance <= $threshold);

        $report = $negativeAccounts->map(fn ($account) => [
            'driver_id' => $account->owner_id,
            'balance_kobo' => $account->balance,
            'balance_naira' => number_format($account->balance / 100, 2),
            'exceeds_threshold' => $account->balance <= $threshold,
        ]);

        Log::warning('NegativeDriverBalanceReport: weekly summary', [
            'total_negative_accounts' => $negativeAccounts->count(),
            'critical_accounts' => $critical->count(),
            'threshold_kobo' => $threshold,
            'total_negative_balance_kobo' => $negativeAccounts->sum('balance'),
            'drivers' => $report->toArray(),
        ]);
    }
}
