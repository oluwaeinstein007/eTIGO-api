<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\Driver;
use App\Models\Journal;
use App\Models\Payout;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a payout's ledger debit in step with the money actually leaving.
 *
 * At most one active (non-reversed) debit exists per payout: a retried
 * payout is debited again after an earlier attempt was reversed, a repeated
 * run of the same attempt reuses its debit, and a failure reverses only the
 * active debit.
 */
class PayoutLedgerService
{
    public function __construct(
        private LedgerService $ledgerService,
    ) {}

    /**
     * Debit the driver's available earnings for the payout, re-checking the
     * balance at transfer time (it may have dropped since the request).
     * Recording a transfer that already happened skips the balance check.
     *
     * @throws \DomainException when the available balance no longer covers the payout
     */
    public function debit(Payout $payout, bool $requireSufficientBalance = true): Journal
    {
        return DB::transaction(function () use ($payout, $requireSufficientBalance) {
            $account = $this->ledgerService->findOrCreateAccount(
                Driver::class,
                $payout->driver_id,
                AccountType::DriverEarningsAvailable,
            );
            $account = Account::whereKey($account->id)->lockForUpdate()->first();

            $activeDebit = $this->activeDebit($payout);

            if ($activeDebit) {
                return $activeDebit;
            }

            if ($requireSufficientBalance && $account->availableBalance() < $payout->amount) {
                throw new \DomainException('Insufficient available balance to complete this payout.');
            }

            $pspClearingAccount = $this->ledgerService->systemAccount(AccountType::PspClearing);

            return $this->ledgerService->postJournal([
                ['account_id' => $account->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $payout->amount],
                ['account_id' => $pspClearingAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $payout->amount],
            ], [
                'description' => "Payout debit for payout {$payout->id}",
                'idempotency_key' => $this->nextDebitKey($payout),
                'metadata' => ['payout_id' => $payout->id],
            ]);
        });
    }

    public function activeDebit(Payout $payout): ?Journal
    {
        return $this->debitJournals($payout)->first(
            fn (Journal $journal) => ! Journal::where('idempotency_key', "REV-{$journal->reference}")->exists(),
        );
    }

    /**
     * Return the payout amount to the driver's available earnings.
     */
    public function reverseActiveDebit(Payout $payout, string $description): ?Journal
    {
        $activeDebit = $this->activeDebit($payout);

        return $activeDebit ? $this->ledgerService->reverse($activeDebit, $description) : null;
    }

    /**
     * @return Collection<int, Journal>
     */
    private function debitJournals(Payout $payout): Collection
    {
        return Journal::where('idempotency_key', 'like', "payout-debit-{$payout->id}%")
            ->orderBy('created_at')
            ->get();
    }

    private function nextDebitKey(Payout $payout): string
    {
        $attempts = $this->debitJournals($payout)->count();

        return $attempts === 0
            ? "payout-debit-{$payout->id}"
            : "payout-debit-{$payout->id}-attempt-".($attempts + 1);
    }
}
