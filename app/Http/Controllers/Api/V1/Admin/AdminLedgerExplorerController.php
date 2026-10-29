<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\JournalResource;
use App\Models\Account;
use App\Models\Journal;
use App\Support\LagosDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminLedgerExplorerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'reference' => ['sometimes', 'string', 'max:100'],
            // Lagos calendar days, on posted_at.
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_id' => ['sometimes', 'uuid'],
        ]);

        $query = Journal::with('entries.account')
            ->orderByDesc('posted_at')
            ->orderByDesc('id');

        if ($request->filled('reference')) {
            $query->where('reference', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->input('reference')).'%');
        }

        // Journals that touch one account, e.g. a driver's earnings.
        if ($request->filled('account_id')) {
            $query->whereHas('entries', fn ($q) => $q->where('account_id', $request->input('account_id')));
        }

        LagosDay::filter($query, 'posted_at', $request->input('from'), $request->input('to'));

        $journals = $query->paginate(20);

        Account::loadOwners(collect($journals->items())->flatMap->entries->pluck('account'));

        return response()->json([
            'journals' => JournalResource::collection($journals),
            'meta' => [
                'current_page' => $journals->currentPage(),
                'last_page' => $journals->lastPage(),
                'per_page' => $journals->perPage(),
                'total' => $journals->total(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            // Lagos calendar days, as on the list.
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = $request->input('from');
        $to = $request->input('to');

        return response()->streamDownload(function () use ($from, $to) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Reference', 'Description', 'Account ID', 'Account Type', 'Account Owner', 'Entry Type', 'Amount (kobo)', 'Running Balance', 'Posted At']);

            LagosDay::filter(Journal::with('entries.account'), 'posted_at', $from, $to)
                ->orderBy('posted_at')
                ->orderBy('id')
                ->chunk(100, function ($journals) use ($handle) {
                    Account::loadOwners($journals->flatMap->entries->pluck('account'));

                    foreach ($journals as $journal) {
                        foreach ($journal->entries as $entry) {
                            fputcsv($handle, [
                                $journal->reference,
                                $journal->description,
                                $entry->account_id,
                                $entry->account->type->value,
                                AccountResource::ownerSummary($entry->account->owner)['name'] ?? '',
                                $entry->type->value,
                                $entry->amount,
                                $entry->running_balance,
                                $journal->posted_at->toIso8601String(),
                            ]);
                        }
                    }
                });

            fclose($handle);
        }, 'ledger-export-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
