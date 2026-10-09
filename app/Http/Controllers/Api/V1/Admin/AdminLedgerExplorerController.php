<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\JournalResource;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminLedgerExplorerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'reference' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = Journal::with('entries.account')
            ->orderByDesc('posted_at');

        if ($request->filled('reference')) {
            $query->where('reference', 'ilike', '%'.$request->input('reference').'%');
        }

        if ($request->filled('from')) {
            $query->where('posted_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('posted_at', '<=', $request->input('to').' 23:59:59');
        }

        $journals = $query->paginate(20);

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
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $from = $request->input('from');
        $to = $request->input('to').' 23:59:59';

        return response()->streamDownload(function () use ($from, $to) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Reference', 'Description', 'Account ID', 'Account Type', 'Entry Type', 'Amount (kobo)', 'Running Balance', 'Posted At']);

            Journal::with('entries.account')
                ->whereBetween('posted_at', [$from, $to])
                ->orderBy('posted_at')
                ->chunk(100, function ($journals) use ($handle) {
                    foreach ($journals as $journal) {
                        foreach ($journal->entries as $entry) {
                            fputcsv($handle, [
                                $journal->reference,
                                $journal->description,
                                $entry->account_id,
                                $entry->account->type->value,
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
