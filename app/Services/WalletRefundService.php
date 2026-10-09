<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\Driver;
use App\Models\Ride;

class WalletRefundService
{
    public function __construct(
        private LedgerService $ledgerService,
    ) {}

    /**
     * Refund an amount to the passenger's wallet.
     */
    public function refundToWallet(Ride $ride, int $amountKobo, ?string $reason = null): void
    {
        $passengerAccount = $this->ledgerService->findOrCreateAccount(
            'App\\Models\\User',
            $ride->passenger_id,
            AccountType::PassengerWallet,
        );

        $refundsAccount = $this->ledgerService->systemAccount(AccountType::Refunds);

        $this->ledgerService->postJournal([
            ['account_id' => $refundsAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $amountKobo],
            ['account_id' => $passengerAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $amountKobo],
        ], [
            'description' => $reason ?? "Refund for ride {$ride->id}",
            'idempotency_key' => "refund-{$ride->id}-{$amountKobo}",
            'metadata' => [
                'ride_id' => $ride->id,
                'refund_amount_kobo' => $amountKobo,
            ],
        ]);
    }

    /**
     * Clawback driver earnings on refund.
     */
    public function clawbackDriverEarnings(Ride $ride, int $amountKobo): void
    {
        $driver = Driver::where('user_id', $ride->driver_id)->firstOrFail();

        $driverAccount = Account::where('owner_type', 'App\\Models\\Driver')
            ->where('owner_id', $driver->id)
            ->where('type', AccountType::DriverEarningsAvailable)
            ->firstOrFail();

        $refundsAccount = $this->ledgerService->systemAccount(AccountType::Refunds);

        $this->ledgerService->postJournal([
            ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $amountKobo],
            ['account_id' => $refundsAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $amountKobo],
        ], [
            'description' => "Driver earnings clawback for ride {$ride->id}",
            'idempotency_key' => "clawback-{$ride->id}-{$amountKobo}",
            'metadata' => [
                'ride_id' => $ride->id,
                'clawback_amount_kobo' => $amountKobo,
            ],
        ]);
    }
}
