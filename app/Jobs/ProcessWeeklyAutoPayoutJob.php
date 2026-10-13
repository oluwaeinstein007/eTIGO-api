<?php

namespace App\Jobs;

use App\Enums\AccountType;
use App\Enums\PayoutPreference;
use App\Enums\PayoutStatus;
use App\Models\Account;
use App\Models\Driver;
use App\Models\Payout;
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

    public function handle(): void
    {
        $minPayout = (int) config('wallet.min_payout', 100000);

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
                        'notes' => 'Weekly auto-payout',
                    ]);

                    ProcessPayoutTransferJob::dispatch($payout->id);

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
