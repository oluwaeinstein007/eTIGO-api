<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LedgerIntegrityCheckCommand extends Command
{
    protected $signature = 'ledger:check';

    protected $description = 'Verify ledger integrity: all journal entries must net to zero system-wide';

    public function handle(): int
    {
        $this->info('Running ledger integrity check...');

        // Check 1: Sum of all debits must equal sum of all credits
        $totals = DB::table('ledger_entries')
            ->selectRaw("
                SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as total_debits,
                SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as total_credits
            ")
            ->first();

        $totalDebits = (int) ($totals->total_debits ?? 0);
        $totalCredits = (int) ($totals->total_credits ?? 0);

        if ($totalDebits !== $totalCredits) {
            $this->error("MISMATCH: Total debits ({$totalDebits}) != Total credits ({$totalCredits})");
            $this->error('Difference: '.abs($totalDebits - $totalCredits).' kobo');

            return self::FAILURE;
        }

        $this->info("Debits = Credits = {$totalDebits} kobo");

        // Check 2: Each journal must be balanced
        $unbalanced = DB::table('journals')
            ->join('ledger_entries', 'journals.id', '=', 'ledger_entries.journal_id')
            ->groupBy('journals.id', 'journals.reference')
            ->havingRaw("
                SUM(CASE WHEN ledger_entries.type = 'debit' THEN ledger_entries.amount ELSE 0 END)
                != SUM(CASE WHEN ledger_entries.type = 'credit' THEN ledger_entries.amount ELSE 0 END)
            ")
            ->select('journals.id', 'journals.reference')
            ->get();

        if ($unbalanced->isNotEmpty()) {
            $this->error("Found {$unbalanced->count()} unbalanced journal(s):");
            foreach ($unbalanced as $journal) {
                $this->error("  - {$journal->reference} (ID: {$journal->id})");
            }

            return self::FAILURE;
        }

        $this->info('All journals are balanced.');

        // Check 3: Account balances match computed balances from entries
        $mismatches = DB::select("
            SELECT a.id, a.type, a.balance as cached_balance,
                COALESCE(SUM(CASE WHEN le.type = 'credit' THEN le.amount ELSE -le.amount END), 0) as computed_balance
            FROM accounts a
            LEFT JOIN ledger_entries le ON le.account_id = a.id
            GROUP BY a.id, a.type, a.balance
            HAVING a.balance != COALESCE(SUM(CASE WHEN le.type = 'credit' THEN le.amount ELSE -le.amount END), 0)
        ");

        if (count($mismatches) > 0) {
            $this->error('Found '.count($mismatches).' account(s) with balance mismatch:');
            foreach ($mismatches as $mismatch) {
                $this->error("  - Account {$mismatch->id} ({$mismatch->type}): cached={$mismatch->cached_balance}, computed={$mismatch->computed_balance}");
            }

            return self::FAILURE;
        }

        $this->info('All account balances match ledger entries.');

        $accountCount = DB::table('accounts')->count();
        $journalCount = DB::table('journals')->count();
        $entryCount = DB::table('ledger_entries')->count();

        $this->info("Summary: {$accountCount} accounts, {$journalCount} journals, {$entryCount} entries.");
        $this->info('Ledger integrity check passed.');

        return self::SUCCESS;
    }
}
