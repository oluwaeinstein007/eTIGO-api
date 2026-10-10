<?php

namespace App\Jobs;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Services\LedgerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SettleDriverEarningsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function handle(LedgerService $ledgerService): void
    {
        $delay = config('wallet.settlement_delay', 'instant');

        if ($delay === 'instant') {
            return;
        }

        $threshold = match ($delay) {
            '24h' => now()->subHours(24),
            'weekly' => now()->subWeek(),
            default => now()->subHours(24),
        };

        $pendingAccounts = Account::where('type', AccountType::DriverEarningsPending)
            ->where('balance', '>', 0)
            ->get();

        $settled = 0;

        foreach ($pendingAccounts as $pendingAccount) {
            $entries = $pendingAccount->entries()
                ->where('type', LedgerEntryType::Credit)
                ->where('created_at', '<=', $threshold)
                ->get();

            if ($entries->isEmpty()) {
                continue;
            }

            $recentCredits = (int) $pendingAccount->entries()
                ->where('type', LedgerEntryType::Credit)
                ->where('created_at', '>', $threshold)
                ->sum('amount');

            $settleAmount = min(
                (int) $entries->sum('amount'),
                max(0, $pendingAccount->balance - $recentCredits),
            );

            if ($settleAmount <= 0) {
                continue;
            }

            $entryIds = $entries->pluck('id')->sort()->implode('-');

            $availableAccount = $ledgerService->findOrCreateAccount(
                $pendingAccount->owner_type,
                $pendingAccount->owner_id,
                AccountType::DriverEarningsAvailable,
            );

            $ledgerService->postJournal([
                ['account_id' => $pendingAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $settleAmount],
                ['account_id' => $availableAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $settleAmount],
            ], [
                'description' => "Settlement: pending → available for driver {$pendingAccount->owner_id}",
                'idempotency_key' => "settle-earnings-{$pendingAccount->owner_id}-{$entryIds}",
                'metadata' => [
                    'driver_id' => $pendingAccount->owner_id,
                    'amount_kobo' => $settleAmount,
                    'delay_policy' => $delay,
                    'entry_count' => $entries->count(),
                ],
            ]);

            $settled++;
        }

        if ($settled > 0) {
            Log::info("Settled earnings for {$settled} drivers (policy: {$delay}).");
        }
    }
}
