<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\BalanceDueReason;
use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\LostItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The lost item return fee (Uber/Bolt practice): when the item is back
 * with the passenger they pay the fee quoted when the driver confirmed, and
 * the driver receives it in full for the trip back. It comes from the
 * wallet when the wallet covers it; otherwise it becomes a balance due the
 * passenger pays from the wallet or card (PassengerBalanceService), which
 * passes it on to the driver. Off (₦0) until the client sets an amount.
 */
class LostItemReturnFeeService
{
    public function __construct(
        private LedgerService $ledgerService,
        private PassengerBalanceService $balanceService,
    ) {}

    /**
     * Collect a pending fee. Safe to run twice: only a pending fee is
     * collected and the ledger journal is idempotent.
     */
    public function collect(string $lostItemId): void
    {
        try {
            DB::transaction(function () use ($lostItemId) {
                $item = LostItem::whereKey($lostItemId)->lockForUpdate()->first();

                if ($item === null || $item->return_fee_status !== 'pending') {
                    return;
                }

                $feeKobo = (int) round($item->returnFeeAmount() * 100);
                $driver = $item->driver_id ? Driver::where('user_id', $item->driver_id)->first() : null;

                if ($driver === null || $feeKobo <= 0) {
                    $this->finish($item, 'waived', ['reason' => 'no_driver_to_pay']);

                    return;
                }

                $wallet = $this->ledgerService->findOrCreateAccount(
                    $item->passenger->getMorphClass(),
                    $item->passenger_id,
                    AccountType::PassengerWallet,
                );
                $wallet = Account::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

                if ($wallet->isActive() && $wallet->availableBalance() >= $feeKobo) {
                    $this->payDriver($wallet, $item, $driver, $feeKobo);
                    $this->finish($item, 'charged', ['via' => 'wallet']);

                    return;
                }

                $balance = $this->balanceService->record(
                    $item->passenger_id,
                    $item->ride,
                    BalanceDueReason::LostItemReturnFee,
                    $feeKobo,
                    $item->driver_id,
                );

                // Balances due are one per ride and reason: a second returned
                // item from the same trip is not charged again.
                if (! $balance?->wasRecentlyCreated) {
                    $this->finish($item, 'waived', ['reason' => 'fee_already_recorded_for_ride', 'balance_due_id' => $balance?->id]);

                    return;
                }

                $this->finish($item, 'balance_due', ['via' => 'balance_due', 'balance_due_id' => $balance->id]);
            });
        } catch (\Throwable $e) {
            // Left pending: the hourly Lost & Found job tries again.
            Log::error('Lost item return fee not collected', ['lost_item_id' => $lostItemId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Passenger wallet → driver earnings, in full.
     */
    private function payDriver(Account $wallet, LostItem $item, Driver $driver, int $feeKobo): void
    {
        $driverAccount = $this->ledgerService->findOrCreateAccount(
            $driver->getMorphClass(),
            $driver->id,
            AppSetting::wallet('settlement_delay') === 'instant'
                ? AccountType::DriverEarningsAvailable
                : AccountType::DriverEarningsPending,
        );

        $this->ledgerService->postJournal([
            ['account_id' => $wallet->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $feeKobo],
            ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $feeKobo],
        ], [
            'description' => "Lost item return fee for {$item->reference_number} (ride {$item->ride_id})",
            'idempotency_key' => "lost-item-return-fee-{$item->id}",
            'metadata' => [
                'lost_item_id' => $item->id,
                'ride_id' => $item->ride_id,
                'fee_kobo' => $feeKobo,
                'driver_id' => $driver->id,
                'category' => 'lost_item_return_fee',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function finish(LostItem $item, string $status, array $details): void
    {
        $item->update(['return_fee_status' => $status]);

        AuditLog::record($item, 'lost_item.return_fee_'.$status, null, null, $details + [
            'amount' => $item->returnFeeAmount(),
        ]);
    }
}
