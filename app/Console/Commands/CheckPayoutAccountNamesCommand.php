<?php

namespace App\Console\Commands;

use App\Enums\BankAccountNameMatch;
use App\Enums\BankAccountReviewStatus;
use App\Enums\PayoutAccountNameCheck;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Services\PayoutAccountSafeguards;
use Illuminate\Console\Command;

/**
 * Accounts saved before the name check existed have no result. This compares
 * each primary account with its driver's verified name; with --apply it
 * records the result and, in review mode, sends mismatches to finance's queue
 * (payouts to them pause until reviewed).
 */
class CheckPayoutAccountNamesCommand extends Command
{
    protected $signature = 'payout-accounts:check-names {--apply : Record the results and send mismatches for review}';

    protected $description = 'Compare existing payout bank account names with drivers\' verified names';

    public function handle(PayoutAccountSafeguards $safeguards): int
    {
        $accounts = BankAccount::query()
            ->where('is_primary', true)
            ->whereNull('name_match_status')
            ->with('driver.user')
            ->get()
            ->filter(fn (BankAccount $account) => $account->driver?->user !== null);

        $review = $this->option('apply') && $safeguards->nameCheckMode() === PayoutAccountNameCheck::Review;
        $mismatches = [];

        foreach ($accounts as $account) {
            $check = $safeguards->checkName($account->driver, $account->account_name);
            $mismatch = $check['status'] === BankAccountNameMatch::Mismatch;

            if ($mismatch) {
                $mismatches[] = [$account->id, $account->account_name, $check['verified_name']];
            }

            if ($this->option('apply')) {
                $account->update([
                    'verified_name' => $check['verified_name'] ?: null,
                    'name_match_status' => $check['status'],
                    'review_status' => $mismatch && $review ? BankAccountReviewStatus::Pending : $account->review_status,
                ]);

                AuditLog::record($account, 'bank_account.name_checked', null, null, [
                    'name_match_status' => $check['status']->value,
                    'review_status' => $account->review_status?->value,
                ]);
            }
        }

        $this->info(sprintf('Checked %d account(s): %d mismatch(es).', $accounts->count(), count($mismatches)));

        if ($mismatches !== []) {
            $this->table(['Bank account', 'Account name', 'Verified name'], $mismatches);
        }

        $this->line(match (true) {
            ! $this->option('apply') => 'Nothing was changed. Run with --apply to record the results.',
            $review => 'Mismatched accounts are waiting for review under /admin/payout-accounts?review_status=pending.',
            default => 'Results recorded; the name check is not in review mode, so no payouts were paused.',
        });

        return self::SUCCESS;
    }
}
