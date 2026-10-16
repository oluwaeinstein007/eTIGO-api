<?php

namespace App\Jobs;

use App\Contracts\WalletGateway;
use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\ReconciliationReport;
use App\Models\WebhookEvent;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailyReconciliationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private ?string $date = null) {}

    public function handle(): void
    {
        $reportDate = $this->date ? now()->parse($this->date) : now()->subDay();
        $dateString = $reportDate->toDateString();
        $startOfDay = $reportDate->copy()->startOfDay();
        $endOfDay = $reportDate->copy()->endOfDay();

        $existingReport = ReconciliationReport::where('report_date', $dateString)->first();
        if ($existingReport) {
            Log::info("Reconciliation report for {$dateString} already exists, skipping.");

            return;
        }

        $gatewayChargesTotal = $this->gatewayTotalKobo($startOfDay, $endOfDay, [
            'paystack' => ['charge.success'],
            'flutterwave' => ['charge.completed'],
        ], topUpsOnly: true);

        $ledgerCreditsTotal = (int) DB::table('ledger_entries')
            ->join('accounts', 'ledger_entries.account_id', '=', 'accounts.id')
            ->where('accounts.type', AccountType::PassengerWallet->value)
            ->where('ledger_entries.type', LedgerEntryType::Credit->value)
            ->whereBetween('ledger_entries.created_at', [$startOfDay, $endOfDay])
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journals')
                    ->whereColumn('journals.id', 'ledger_entries.journal_id')
                    ->where('journals.description', 'like', '%top-up%');
            })
            ->sum('ledger_entries.amount');

        $gatewayTransfersTotal = $this->gatewayTotalKobo($startOfDay, $endOfDay, [
            'paystack' => ['transfer.success'],
            'flutterwave' => ['transfer.completed'],
        ]) + $this->paidWithoutWebhookKobo($startOfDay, $endOfDay);

        $ledgerPayoutsTotal = (int) DB::table('payouts')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$startOfDay, $endOfDay])
            ->sum('amount');

        $mismatches = [];

        $chargesDiff = abs($gatewayChargesTotal - $ledgerCreditsTotal);
        if ($chargesDiff > 0) {
            $mismatches[] = [
                'type' => 'charges',
                'gateway_total' => $gatewayChargesTotal,
                'ledger_total' => $ledgerCreditsTotal,
                'difference' => $chargesDiff,
            ];
        }

        $transfersDiff = abs($gatewayTransfersTotal - $ledgerPayoutsTotal);
        if ($transfersDiff > 0) {
            $mismatches[] = [
                'type' => 'transfers',
                'gateway_total' => $gatewayTransfersTotal,
                'ledger_total' => $ledgerPayoutsTotal,
                'difference' => $transfersDiff,
            ];
        }

        $status = empty($mismatches) ? 'clean' : 'mismatched';

        ReconciliationReport::firstOrCreate(
            ['report_date' => $dateString],
            [
                'gateway_charges_total' => $gatewayChargesTotal,
                'ledger_credits_total' => $ledgerCreditsTotal,
                'gateway_transfers_total' => $gatewayTransfersTotal,
                'ledger_payouts_total' => $ledgerPayoutsTotal,
                'mismatches_count' => count($mismatches),
                'mismatches' => $mismatches ?: null,
                'status' => $status,
            ],
        );

        if ($status === 'mismatched') {
            Log::warning("Reconciliation mismatch detected for {$dateString}", [
                'mismatches' => $mismatches,
            ]);
        }

        Log::info("Daily reconciliation report generated for {$dateString}: {$status}");
    }

    /**
     * Total, in kobo, of the successful gateway events of the day that were
     * processed. Amounts are read from the event's `data`: Paystack reports
     * kobo, Flutterwave naira.
     *
     * @param  array<string, list<string>>  $eventTypes  Event types per provider
     */
    private function gatewayTotalKobo(CarbonInterface $from, CarbonInterface $to, array $eventTypes, bool $topUpsOnly = false): int
    {
        $total = 0;

        WebhookEvent::whereNotNull('processed_at')
            ->whereBetween('created_at', [$from, $to])
            ->where(function ($query) use ($eventTypes) {
                foreach ($eventTypes as $provider => $types) {
                    $query->orWhere(fn ($query) => $query->where('provider', $provider)->whereIn('event_type', $types));
                }
            })
            ->lazyById()
            ->each(function (WebhookEvent $event) use (&$total, $topUpsOnly) {
                $data = $event->payload['data'] ?? [];
                $reference = (string) ($data['tx_ref'] ?? $data['reference'] ?? '');

                if (! in_array(strtolower((string) ($data['status'] ?? '')), ['success', 'successful'], true)) {
                    return;
                }

                if ($topUpsOnly && ! str_starts_with($reference, 'TOPUP-')) {
                    return;
                }

                $amount = (float) ($data['amount'] ?? 0);
                $total += $event->provider === 'flutterwave' ? (int) round($amount * 100) : (int) $amount;
            });

        return $total;
    }

    /**
     * Total, in kobo, that Paystack reports for the day's paid payouts whose
     * success webhook never arrived. Such a payout was settled by asking
     * Paystack about the transfer (an admin's verify, or the payout sweep),
     * so Paystack is asked again here. A payout Paystack does not confirm
     * adds nothing and shows up as a mismatch.
     */
    private function paidWithoutWebhookKobo(CarbonInterface $from, CarbonInterface $to): int
    {
        $total = 0;

        Payout::where('status', PayoutStatus::Paid)
            ->whereBetween('paid_at', [$from, $to])
            ->whereNotNull('gateway_reference')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('webhook_events')
                    ->whereNotNull('processed_at')
                    ->whereIn('event_type', ['transfer.success', 'transfer.completed'])
                    ->whereRaw("webhook_events.payload->'data'->>'reference' = payouts.gateway_reference");
            })
            ->lazyById()
            ->each(function (Payout $payout) use (&$total) {
                try {
                    $transfer = app(WalletGateway::class)->verifyTransfer($payout->gateway_reference);
                } catch (\Throwable $e) {
                    Log::warning('Reconciliation could not check a payout with Paystack', [
                        'payout_id' => $payout->id,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }

                if ($transfer !== null && strtolower((string) $transfer['status']) === 'success') {
                    $total += (int) $transfer['amount'];
                }
            });

        return $total;
    }
}
