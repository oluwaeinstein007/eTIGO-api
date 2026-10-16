<?php

namespace App\Jobs;

use App\Enums\AccountType;
use App\Enums\PayoutPreference;
use App\Enums\PayoutStatus;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Payout;
use App\Services\PayoutAccountSafeguards;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWeeklyAutoPayoutJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'weekly-auto-payout';
    }

    public function handle(PayoutAccountSafeguards $safeguards): void
    {
        $minPayout = (int) AppSetting::wallet('min_payout');

        $drivers = Driver::where('payout_preference', PayoutPreference::WeeklyAuto)
            ->whereHas('primaryBankAccount', fn ($q) => $q->where('is_verified', true))
            ->whereDoesntHave('payouts', fn ($q) => $q->whereIn('status', [
                PayoutStatus::Requested,
                PayoutStatus::Approved,
                PayoutStatus::Processing,
            ]))
            ->get();

        $processed = 0;

        foreach ($drivers as $driver) {
            // Held, under review or rejected: skip this week, pay next time.
            if ($block = $safeguards->blockFor($driver->primaryBankAccount)) {
                Log::info('Weekly auto-payout skipped for driver', ['driver_id' => $driver->id, 'reason' => $block['code']]);

                continue;
            }

            try {
                DB::transaction(function () use ($driver, $minPayout, &$processed) {
                    $account = Account::where('owner_type', 'App\\Models\\Driver')
                        ->where('owner_id', $driver->id)
                        ->where('type', AccountType::DriverEarningsAvailable)
                        ->lockForUpdate()
                        ->first();

                    if (! $account) {
                        return;
                    }

                    $balance = $account->availableBalance();

                    if ($balance < $minPayout) {
                        return;
                    }

                    $bankAccount = $driver->primaryBankAccount;

                    $payout = Payout::create([
                        'driver_id' => $driver->id,
                        'bank_account_id' => $bankAccount->id,
                        'amount' => $balance,
                        'status' => PayoutStatus::Approved,
                        'requested_at' => now(),
                        'approved_at' => now(),
                    ]);

                    AuditLog::record($payout, 'payout.auto_requested', null, null, [
                        'preference' => PayoutPreference::WeeklyAuto->value,
                    ]);

                    ProcessPayoutTransferJob::dispatch($payout->id)->afterCommit();

                    $processed++;
                });
            } catch (\Throwable $e) {
                Log::error('Weekly auto-payout failed for driver', [
                    'driver_id' => $driver->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Weekly auto-payout completed: {$processed} payouts initiated");
    }
}
