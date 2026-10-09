<?php

namespace App\Jobs;

use App\Contracts\PaystackGateway;
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
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    public function handle(PaystackGateway $paystackGateway, LedgerService $ledgerService): void
    {
        $payout = Payout::with(['bankAccount', 'driver'])->findOrFail($this->payoutId);

        if (! $payout->isApproved()) {
            Log::warning("Payout {$this->payoutId} is not in approved status, skipping.");

            return;
        }

        // Pre-debit driver earnings
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

        try {
            $recipient = $paystackGateway->createTransferRecipient([
                'type' => 'nuban',
                'name' => $payout->bankAccount->account_name,
                'account_number' => $payout->bankAccount->account_number,
                'bank_code' => $payout->bankAccount->bank_code,
                'currency' => 'NGN',
            ]);

            $reference = 'PAYOUT-'.strtoupper(Str::random(12));

            $transfer = $paystackGateway->initiateTransfer([
                'source' => 'balance',
                'amount' => $payout->amount,
                'recipient' => $recipient['recipient_code'],
                'reason' => "E-tiGo driver payout #{$payout->id}",
                'reference' => $reference,
            ]);

            $payout->update([
                'status' => PayoutStatus::Processing,
                'gateway_transfer_id' => $transfer['transfer_code'],
                'gateway_reference' => $reference,
            ]);

            Log::info("Payout transfer initiated: {$payout->id}, transfer: {$transfer['transfer_code']}");
        } catch (\Throwable $e) {
            $payout->update([
                'status' => PayoutStatus::Failed,
                'failure_reason' => $e->getMessage(),
            ]);

            // Reverse the pre-debit
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
