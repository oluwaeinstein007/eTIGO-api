<?php

namespace App\Jobs;

use App\Contracts\FlutterwaveWalletGateway;
use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Models\Journal;
use App\Models\Payout;
use App\Services\LedgerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPayoutTransferJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $payoutId) {}

    public function uniqueId(): string
    {
        return $this->payoutId;
    }

    public function handle(FlutterwaveWalletGateway $flutterwaveGateway, LedgerService $ledgerService): void
    {
        $payout = Payout::with(['bankAccount', 'driver'])->findOrFail($this->payoutId);

        if (! $payout->isApproved()) {
            Log::warning("Payout {$this->payoutId} is not in approved status, skipping.");

            return;
        }

        $driverAccount = $ledgerService->findOrCreateAccount(
            'App\\Models\\Driver',
            $payout->driver_id,
            AccountType::DriverEarningsAvailable,
        );
        $pspClearingAccount = $ledgerService->systemAccount(AccountType::PspClearing);

        $ledgerService->postJournal([
            ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $payout->amount],
            ['account_id' => $pspClearingAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $payout->amount],
        ], [
            'description' => "Payout debit for payout {$payout->id}",
            'idempotency_key' => "payout-debit-{$payout->id}",
            'metadata' => ['payout_id' => $payout->id],
        ]);

        $reference = "PAYOUT-{$payout->id}";

        $payout->update([
            'gateway_reference' => $reference,
        ]);

        try {
            $transfer = $flutterwaveGateway->initiateTransfer([
                'account_bank' => $payout->bankAccount->bank_code,
                'account_number' => $payout->bankAccount->account_number,
                'amount' => $payout->amount / 100,
                'narration' => "E-tiGo driver payout #{$payout->id}",
                'currency' => 'NGN',
                'reference' => $reference,
            ]);

            $payout->update([
                'status' => PayoutStatus::Processing,
                'gateway_transfer_id' => (string) $transfer['id'],
            ]);

            Log::info("Payout transfer initiated: {$payout->id}, transfer ID: {$transfer['id']}");
        } catch (ConnectionException $e) {
            Log::error("Payout transfer network error: {$payout->id}", ['error' => $e->getMessage()]);
            throw $e;
        } catch (\Throwable $e) {
            $payout->update([
                'status' => PayoutStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);

            $journal = DB::table('journals')
                ->where('idempotency_key', "payout-debit-{$payout->id}")
                ->first();

            if ($journal) {
                $ledgerService->reverse(
                    Journal::find($journal->id),
                    "Payout failed: reversal for payout {$payout->id}"
                );
            }

            Log::error("Payout transfer failed: {$payout->id}", ['error' => $e->getMessage()]);
        }
    }
}
