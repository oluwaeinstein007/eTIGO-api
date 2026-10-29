<?php

namespace App\Jobs;

use App\Contracts\WalletGateway;
use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\ReconciliationReport;
use App\Models\WebhookEvent;
use App\Support\LagosDay;
use Carbon\CarbonImmutable;
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

    /** Most mismatched transactions listed per kind; the totals still cover all of them. */
    public const MAX_ITEMS = 200;

    public function handle(): void
    {
        // Report days are Lagos calendar days, like every date on the dashboard.
        $dateString = $this->date
            ? CarbonImmutable::parse($this->date)->toDateString()
            : CarbonImmutable::now(LagosDay::TIMEZONE)->subDay()->toDateString();
        $startOfDay = LagosDay::start($dateString);
        $endOfDay = LagosDay::end($dateString);

        $existingReport = ReconciliationReport::where('report_date', $dateString)->first();
        if ($existingReport) {
            Log::info("Reconciliation report for {$dateString} already exists, skipping.");

            return;
        }

        $gatewayCharges = $this->gatewayAmountsByReference($startOfDay, $endOfDay, [
            'paystack' => ['charge.success'],
            'flutterwave' => ['charge.completed'],
        ], topUpsOnly: true);

        $ledgerCredits = [];
        DB::table('ledger_entries')
            ->join('accounts', 'ledger_entries.account_id', '=', 'accounts.id')
            ->join('journals', 'journals.id', '=', 'ledger_entries.journal_id')
            ->where('accounts.type', AccountType::PassengerWallet->value)
            ->where('ledger_entries.type', LedgerEntryType::Credit->value)
            ->where('ledger_entries.created_at', '>=', $startOfDay)
            ->where('ledger_entries.created_at', '<', $endOfDay)
            ->where('journals.description', 'like', '%top-up%')
            ->select([
                'ledger_entries.amount',
                'ledger_entries.account_id',
                'journals.id as journal_id',
                DB::raw("journals.metadata->>'reference' as reference"),
            ])
            ->orderBy('ledger_entries.id')
            ->each(function ($row) use (&$ledgerCredits) {
                $key = $row->reference ?: "journal:{$row->journal_id}";
                $ledgerCredits[$key] = [
                    'amount' => ($ledgerCredits[$key]['amount'] ?? 0) + (int) $row->amount,
                    'account_id' => $row->account_id,
                    'journal_id' => $row->journal_id,
                ];
            });

        $gatewayTransfers = $this->gatewayAmountsByReference($startOfDay, $endOfDay, [
            'paystack' => ['transfer.success'],
            'flutterwave' => ['transfer.completed'],
        ]);
        foreach ($this->paidWithoutWebhook($startOfDay, $endOfDay) as $reference => $amount) {
            $gatewayTransfers[$reference] = ($gatewayTransfers[$reference] ?? 0) + $amount;
        }

        $ledgerPayouts = [];
        Payout::where('status', PayoutStatus::Paid)
            ->where('paid_at', '>=', $startOfDay)
            ->where('paid_at', '<', $endOfDay)
            ->lazyById()
            ->each(function (Payout $payout) use (&$ledgerPayouts) {
                // The transfer reference sent to the gateway (ProcessPayoutTransferJob).
                $key = $payout->gateway_reference ?: "PAYOUT-{$payout->id}";
                $ledgerPayouts[$key] = [
                    'amount' => ($ledgerPayouts[$key]['amount'] ?? 0) + (int) $payout->amount,
                    'payout_id' => $payout->id,
                    'payout_reference' => $payout->reference_number,
                ];
            });

        $mismatches = array_values(array_filter([
            $this->mismatch('charges', $gatewayCharges, $ledgerCredits),
            $this->mismatch('transfers', $gatewayTransfers, $ledgerPayouts),
        ]));

        $status = empty($mismatches) ? 'clean' : 'mismatched';

        ReconciliationReport::firstOrCreate(
            ['report_date' => $dateString],
            [
                'gateway_charges_total' => array_sum($gatewayCharges),
                'ledger_credits_total' => array_sum(array_column($ledgerCredits, 'amount')),
                'gateway_transfers_total' => array_sum($gatewayTransfers),
                'ledger_payouts_total' => array_sum(array_column($ledgerPayouts, 'amount')),
                'mismatches_count' => count($mismatches),
                'mismatches' => $mismatches ?: null,
                'status' => $status,
            ],
        );

        if ($status === 'mismatched') {
            Log::warning("Reconciliation mismatch detected for {$dateString}", [
                'mismatches' => array_map(fn (array $m) => array_diff_key($m, ['items' => true]), $mismatches),
            ]);
        }

        Log::info("Daily reconciliation report generated for {$dateString}: {$status}");
    }

    /**
     * Compare one kind of money movement transaction by transaction, keyed by
     * the gateway reference. Null when the totals and every transaction agree.
     *
     * @param  array<string, int>  $gateway  Kobo per gateway reference
     * @param  array<string, array{amount: int}&array<string, mixed>>  $ledger  Kobo (and ids) per reference
     * @return array<string, mixed>|null
     */
    private function mismatch(string $type, array $gateway, array $ledger): ?array
    {
        $items = [];

        foreach (array_unique([...array_keys($gateway), ...array_keys($ledger)]) as $reference) {
            $gatewayAmount = $gateway[$reference] ?? null;
            $ledgerAmount = $ledger[$reference]['amount'] ?? null;

            if ($gatewayAmount === $ledgerAmount) {
                continue;
            }

            $items[] = [
                // The gateway reference; "journal:…" for a top-up journal without one.
                'reference' => $reference,
                'issue' => match (true) {
                    $ledgerAmount === null => 'missing_in_ledger',
                    $gatewayAmount === null => 'missing_at_gateway',
                    default => 'amount_mismatch',
                },
                'gateway_amount' => $gatewayAmount,
                'ledger_amount' => $ledgerAmount,
                'difference' => abs(($gatewayAmount ?? 0) - ($ledgerAmount ?? 0)),
                ...array_diff_key($ledger[$reference] ?? [], ['amount' => true]),
            ];
        }

        $gatewayTotal = array_sum($gateway);
        $ledgerTotal = array_sum(array_column($ledger, 'amount'));

        if ($items === [] && $gatewayTotal === $ledgerTotal) {
            return null;
        }

        usort($items, fn (array $a, array $b) => $b['difference'] <=> $a['difference']);

        return [
            'type' => $type,
            'gateway_total' => $gatewayTotal,
            'ledger_total' => $ledgerTotal,
            'difference' => abs($gatewayTotal - $ledgerTotal),
            'items_count' => count($items),
            'items_truncated' => count($items) > self::MAX_ITEMS,
            'items' => array_slice($items, 0, self::MAX_ITEMS),
        ];
    }

    /**
     * Kobo per reference of the successful gateway events of the day that
     * were processed. Amounts are read from the event's `data`: Paystack
     * reports kobo, Flutterwave naira.
     *
     * @param  array<string, list<string>>  $eventTypes  Event types per provider
     * @return array<string, int>
     */
    private function gatewayAmountsByReference(CarbonInterface $from, CarbonInterface $to, array $eventTypes, bool $topUpsOnly = false): array
    {
        $amounts = [];

        WebhookEvent::whereNotNull('processed_at')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->where(function ($query) use ($eventTypes) {
                foreach ($eventTypes as $provider => $types) {
                    $query->orWhere(fn ($query) => $query->where('provider', $provider)->whereIn('event_type', $types));
                }
            })
            ->lazyById()
            ->each(function (WebhookEvent $event) use (&$amounts, $topUpsOnly) {
                $data = $event->payload['data'] ?? [];
                $reference = (string) ($data['tx_ref'] ?? $data['reference'] ?? '');

                if (! in_array(strtolower((string) ($data['status'] ?? '')), ['success', 'successful'], true)) {
                    return;
                }

                if ($topUpsOnly && ! str_starts_with($reference, 'TOPUP-')) {
                    return;
                }

                $amount = (float) ($data['amount'] ?? 0);
                $key = $reference !== '' ? $reference : "webhook:{$event->id}";
                $amounts[$key] = ($amounts[$key] ?? 0) + ($event->provider === 'flutterwave' ? (int) round($amount * 100) : (int) $amount);
            });

        return $amounts;
    }

    /**
     * Kobo per transfer reference that Paystack reports for the day's paid payouts whose
     * success webhook never arrived. Such a payout was settled by asking
     * Paystack about the transfer (an admin's verify, or the payout sweep),
     * so Paystack is asked again here. A payout Paystack does not confirm
     * adds nothing and shows up as a mismatch.
     *
     * @return array<string, int>
     */
    private function paidWithoutWebhook(CarbonInterface $from, CarbonInterface $to): array
    {
        $amounts = [];

        Payout::where('status', PayoutStatus::Paid)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->whereNotNull('gateway_reference')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('webhook_events')
                    ->whereNotNull('processed_at')
                    ->whereIn('event_type', ['transfer.success', 'transfer.completed'])
                    ->whereRaw("webhook_events.payload->'data'->>'reference' = payouts.gateway_reference");
            })
            ->lazyById()
            ->each(function (Payout $payout) use (&$amounts) {
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
                    $amounts[$payout->gateway_reference] = (int) $transfer['amount'];
                }
            });

        return $amounts;
    }
}
