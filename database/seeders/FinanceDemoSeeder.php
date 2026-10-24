<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\AdjustmentStatus;
use App\Enums\AdjustmentType;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Enums\WalletTransactionStatus;
use App\Models\Account;
use App\Models\Adjustment;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Payout;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\LedgerService;
use App\Services\PayoutLedgerService;
use Database\Seeders\Concerns\SeedsDemoActivity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Money movements for the admin Finance screens, posted through the ledger
 * so balances and ledger:check agree:
 *
 * - Driver payouts paid, awaiting approval, rejected, and failed
 *   then reversed back to the driver's balance.
 * - Manual adjustments approved (a goodwill credit), pending and rejected.
 * - Wallet top-ups the passenger abandoned at the payment page or the bank
 *   declined (no money moved).
 *
 * Only @ops-demo.etigo.com drivers and city demo passengers are used.
 *
 * php artisan db:seed --class=FinanceDemoSeeder
 */
class FinanceDemoSeeder extends Seeder
{
    use SeedsDemoActivity;

    public function run(LedgerService $ledger, PayoutLedgerService $payoutLedger): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping FinanceDemoSeeder in production.');

            return;
        }

        $finance = User::where('email', 'finance@etigo.com')->first();
        $superAdmin = User::where('email', 'admin@etigo.com')->first();

        $drivers = Driver::whereHas('user', fn ($q) => $q->where('email', 'like', '%@ops-demo.etigo.com'))
            ->whereHas('bankAccounts')
            ->with('user', 'bankAccounts')
            ->get()
            ->map(fn (Driver $driver) => [$driver, Account::where('owner_type', Driver::class)->where('owner_id', $driver->id)->where('type', AccountType::DriverEarningsAvailable)->first()])
            ->filter(fn (array $pair) => $pair[1] !== null && $pair[1]->balance >= 1_000_000)
            ->sortByDesc(fn (array $pair) => $pair[1]->balance)
            ->values();

        if (! $finance || ! $superAdmin || $drivers->count() < 5) {
            // Where earnings are held (wallet.settlement_delay), trip earnings
            // reach the available balance only after the hourly settlement.
            $this->command?->warn('Needs five demo drivers with at least ₦10,000 available. Run AdminSeeder and CityActivityDemoSeeder first; if earnings are held, run this again after the hourly settlement.');

            return;
        }

        if (Payout::whereIn('driver_id', $drivers->map(fn (array $pair) => $pair[0]->id))->exists()) {
            return;
        }

        $this->payouts($drivers->all(), $finance, $payoutLedger);
        $this->adjustments($drivers->all(), $finance, $superAdmin, $ledger);
        $this->failedTopUps();
    }

    /**
     * @param  list<array{0: Driver, 1: Account}>  $drivers
     */
    private function payouts(array $drivers, User $finance, PayoutLedgerService $payoutLedger): void
    {
        // No payout is left processing: its made-up transfer reference
        // would send the payout reconciliation to Paystack, which has no such
        // transfer, every 15 minutes.
        $plans = [
            [PayoutStatus::Paid, 6, null],
            [PayoutStatus::Paid, 1, null],
            [PayoutStatus::Requested, 0, null],
            [PayoutStatus::Rejected, 2, 'Bank account under review after a change of details.'],
            [PayoutStatus::Reversed, 3, 'Destination bank was unavailable; the amount is back in the driver\'s balance.'],
        ];

        foreach ($plans as $n => [$status, $daysAgo, $reason]) {
            [$driver, $account] = $drivers[$n];
            // Today's requests are a few hours old, so every step (and the
            // processing payout's approval two hours later) is in the past.
            $requestedAt = $daysAgo === 0
                ? now()->subHours(6 - $n)->second(0)
                : now()->subDays($daysAgo)->setTime(9 + $n, 15);
            // Whole thousands, never more than the driver has.
            $amount = (int) (floor($account->fresh()->balance * 0.6 / 100_000) * 100_000);

            $payout = $this->at($requestedAt, fn () => Payout::create([
                'driver_id' => $driver->id,
                'bank_account_id' => $driver->bankAccounts->first()->id,
                'amount' => $amount,
                'status' => PayoutStatus::Requested,
                'requested_at' => now(),
            ]));
            $this->at($requestedAt, fn () => AuditLog::record($payout, 'payout.requested', $driver->user));

            if ($status === PayoutStatus::Requested) {
                continue;
            }

            if ($status === PayoutStatus::Rejected) {
                $this->at($requestedAt->copy()->addHours(3), fn () => $payout->update(['status' => PayoutStatus::Rejected, 'failure_reason' => $reason]));

                continue;
            }

            $approvedAt = $requestedAt->copy()->addHours(2);
            $this->at($approvedAt, function () use ($payout, $finance, $payoutLedger) {
                $payout->update(['status' => PayoutStatus::Approved, 'approved_at' => now(), 'approved_by_admin_id' => $finance->id]);
                AuditLog::record($payout, 'payout.approved', $finance);

                // Money leaves the driver's balance when the transfer starts.
                $payoutLedger->debit($payout);
                $payout->update([
                    'status' => PayoutStatus::Processing,
                    'gateway_transfer_id' => (string) random_int(80_000_000, 89_999_999),
                    'gateway_reference' => 'PAYOUT-'.strtoupper(Str::random(10)),
                    'gateway_recipient_code' => 'RCP_'.strtolower(Str::random(14)),
                    'provider_status' => 'pending',
                ]);
            });

            match ($status) {
                PayoutStatus::Paid => $this->at($approvedAt->copy()->addMinutes(4), fn () => $payout->update(['status' => PayoutStatus::Paid, 'provider_status' => 'success', 'paid_at' => now()])),
                PayoutStatus::Reversed => $this->at($approvedAt->copy()->addMinutes(6), function () use ($payout, $reason, $payoutLedger) {
                    $payout->update(['status' => PayoutStatus::Failed, 'provider_status' => 'failed', 'failure_reason' => $reason]);
                    $payoutLedger->reverseActiveDebit($payout, "Payout failure reversal for payout {$payout->id}");
                    $payout->update(['status' => PayoutStatus::Reversed]);
                }),
                default => null,
            };
        }
    }

    /**
     * @param  list<array{0: Driver, 1: Account}>  $drivers
     */
    private function adjustments(array $drivers, User $finance, User $approver, LedgerService $ledger): void
    {
        $passenger = User::where('email', 'like', '%.lagos@demo.etigo.com')->orderBy('email')->first();
        $wallet = $passenger ? $ledger->findOrCreateAccount($passenger->getMorphClass(), $passenger->id, AccountType::PassengerWallet) : null;

        if (! $wallet) {
            return;
        }

        // A goodwill credit, approved by a second admin and posted.
        $at = now()->subDays(4)->setTime(14, 0);
        $credit = $this->at($at, fn () => Adjustment::create([
            'account_id' => $wallet->id,
            'type' => AdjustmentType::Credit,
            'amount' => 200_000,
            'reason' => 'Goodwill credit: driver did not show up for a booked airport trip.',
            'status' => AdjustmentStatus::Pending,
            'created_by_admin_id' => $finance->id,
        ]));
        $this->at($at->copy()->addHours(1), function () use ($credit, $approver, $ledger) {
            $journal = $ledger->postJournal([
                ['account_id' => $ledger->systemAccount(AccountType::Refunds)->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $credit->amount],
                ['account_id' => $credit->account_id, 'type' => LedgerEntryType::Credit->value, 'amount' => $credit->amount],
            ], [
                'description' => "Manual adjustment: {$credit->reason}",
                'idempotency_key' => "adjustment-{$credit->id}",
                'metadata' => ['adjustment_id' => $credit->id],
            ]);
            $credit->update(['status' => AdjustmentStatus::Approved, 'approved_by_admin_id' => $approver->id, 'journal_id' => $journal->id, 'approved_at' => now()]);
        });

        // Waiting for a second admin.
        $this->at(now()->subHours(5), fn () => Adjustment::create([
            'account_id' => $drivers[0][1]->id,
            'type' => AdjustmentType::Debit,
            'amount' => 150_000,
            'reason' => 'Recover a toll fee refunded to the passenger on a disputed trip.',
            'status' => AdjustmentStatus::Pending,
            'created_by_admin_id' => $finance->id,
        ]));

        // Turned down.
        $this->at(now()->subDays(2)->setTime(11, 0), fn () => Adjustment::create([
            'account_id' => $drivers[1][1]->id,
            'type' => AdjustmentType::Credit,
            'amount' => 500_000,
            'reason' => 'Driver asked for fuel support after a long airport wait.',
            'status' => AdjustmentStatus::Rejected,
            'created_by_admin_id' => $finance->id,
        ]));
    }

    private function failedTopUps(): void
    {
        $passengers = User::where('email', 'like', '%@demo.etigo.com')->where('type', 'passenger')->orderBy('email')->take(4)->get();

        foreach ($passengers as $n => $passenger) {
            $wallet = Account::where('owner_type', $passenger->getMorphClass())->where('owner_id', $passenger->id)->where('type', AccountType::PassengerWallet)->first();

            if (! $wallet) {
                continue;
            }

            $at = now()->subDays($n + 1)->setTime(20, 10);
            $abandoned = $n % 2 === 0;

            WalletTransaction::create([
                'account_id' => $wallet->id,
                'reference' => 'TOPUP-'.strtoupper(Str::random(12)),
                'amount' => [500_000, 1_000_000, 2_000_000, 300_000][$n],
                'save_card' => false,
                'status' => $abandoned ? WalletTransactionStatus::Abandoned : WalletTransactionStatus::Failed,
                'abandoned_at' => $abandoned ? $at->copy()->addMinutes(30) : null,
            ])->forceFill(['created_at' => $at, 'updated_at' => $at->copy()->addMinutes($abandoned ? 30 : 2)])->saveQuietly();
        }
    }
}
