<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\Journal;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LedgerService
{
    /**
     * Post a balanced journal with one or more debit/credit entries.
     *
     * @param  array{account_id: string, type: string, amount: int}[]  $lines
     * @param  array{description: string, idempotency_key?: string|null, metadata?: array|null}  $options
     */
    public function postJournal(array $lines, array $options): Journal
    {
        $idempotencyKey = $options['idempotency_key'] ?? null;

        if ($idempotencyKey) {
            $existing = Journal::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        $this->validateBalance($lines);

        try {
            return DB::transaction(function () use ($lines, $options, $idempotencyKey) {
                if ($idempotencyKey) {
                    $existing = Journal::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                    if ($existing) {
                        return $existing;
                    }
                }

                $journal = Journal::create([
                    'reference' => $this->generateReference(),
                    'description' => $options['description'],
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $options['metadata'] ?? null,
                    'posted_at' => now(),
                    'created_at' => now(),
                ]);

                $accountIds = array_unique(array_column($lines, 'account_id'));
                $accounts = Account::whereIn('id', $accountIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($accounts as $account) {
                    if ($account->status !== AccountStatus::Active) {
                        throw new \DomainException("Account {$account->id} is {$account->status->value}, cannot post.");
                    }
                }

                foreach ($lines as $line) {
                    $account = $accounts->get($line['account_id']);
                    if (! $account) {
                        throw new \DomainException("Account {$line['account_id']} not found.");
                    }

                    $entryType = LedgerEntryType::from($line['type']);
                    $amount = (int) $line['amount'];

                    if ($amount <= 0) {
                        throw new \DomainException('Ledger entry amount must be positive.');
                    }

                    $balanceDelta = $entryType === LedgerEntryType::Credit ? $amount : -$amount;
                    $newBalance = $account->balance + $balanceDelta;

                    $updated = Account::where('id', $account->id)
                        ->where('balance_version', $account->balance_version)
                        ->update([
                            'balance' => $newBalance,
                            'balance_version' => $account->balance_version + 1,
                        ]);

                    if ($updated === 0) {
                        throw new \DomainException('Concurrent balance modification detected. Retry the transaction.');
                    }

                    LedgerEntry::create([
                        'journal_id' => $journal->id,
                        'account_id' => $account->id,
                        'type' => $entryType,
                        'amount' => $amount,
                        'running_balance' => $newBalance,
                        'created_at' => now(),
                    ]);

                    $account->balance = $newBalance;
                    $account->balance_version++;
                }

                return $journal;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($idempotencyKey && (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate'))) {
                $existing = Journal::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    /**
     * Reverse a journal by creating a counter-journal with inverted entries.
     */
    public function reverse(Journal $original, ?string $description = null): Journal
    {
        $entries = $original->entries()->get();

        if ($entries->isEmpty()) {
            throw new \DomainException('Cannot reverse a journal with no entries.');
        }

        $lines = $entries->map(fn (LedgerEntry $entry) => [
            'account_id' => $entry->account_id,
            'type' => $entry->isDebit() ? LedgerEntryType::Credit->value : LedgerEntryType::Debit->value,
            'amount' => $entry->amount,
        ])->all();

        return $this->postJournal($lines, [
            'description' => $description ?? "Reversal of {$original->reference}",
            'idempotency_key' => "REV-{$original->reference}",
            'metadata' => [
                'reversal_of' => $original->reference,
                'original_journal_id' => $original->id,
            ],
        ]);
    }

    /**
     * Get balance breakdown for an account.
     *
     * @return array{balance: int, held: int, available: int, pending: int}
     */
    public function getBalance(Account $account): array
    {
        $held = $account->activeHoldsTotal();

        return [
            'balance' => $account->balance,
            'held' => $held,
            'available' => $account->balance - $held,
        ];
    }

    /**
     * Find or create a user-scoped account.
     */
    public function findOrCreateAccount(
        string $ownerType,
        string $ownerId,
        AccountType $type,
        string $currency = 'NGN',
    ): Account {
        return Account::firstOrCreate(
            [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'type' => $type,
            ],
            [
                'currency' => $currency,
                'status' => AccountStatus::Active,
                'balance' => 0,
                'balance_version' => 0,
            ],
        );
    }

    /**
     * Get a system account by type. System accounts have no owner.
     */
    public function systemAccount(AccountType $type): Account
    {
        return Account::where('type', $type)
            ->where('owner_type', 'system')
            ->firstOrFail();
    }

    private function validateBalance(array $lines): void
    {
        $debits = 0;
        $credits = 0;

        foreach ($lines as $line) {
            $entryType = LedgerEntryType::tryFrom($line['type']);
            if (! $entryType) {
                throw new \DomainException("Invalid ledger entry type: {$line['type']}.");
            }

            $amount = (int) $line['amount'];
            if ($entryType === LedgerEntryType::Debit) {
                $debits += $amount;
            } else {
                $credits += $amount;
            }
        }

        if ($debits !== $credits) {
            throw new \DomainException("Journal does not balance: debits={$debits}, credits={$credits}.");
        }
    }

    private function generateReference(): string
    {
        return 'JRN-'.strtoupper(Str::random(12)).'-'.now()->format('ymd');
    }
}
