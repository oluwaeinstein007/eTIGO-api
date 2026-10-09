<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private LedgerService $ledgerService) {}

    public function show(Request $request): JsonResponse
    {
        $account = $this->ledgerService->findOrCreateAccount(
            $request->user()->getMorphClass(),
            $request->user()->id,
            AccountType::PassengerWallet,
        );

        $balance = $this->ledgerService->getBalance($account);

        return response()->json([
            'wallet' => [
                'id' => $account->id,
                'currency' => $account->currency,
                'status' => $account->status->value,
                'balance' => $balance['balance'],
                'available' => $balance['available'],
                'held' => $balance['held'],
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', 'in:debit,credit'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $account = $this->ledgerService->findOrCreateAccount(
            $request->user()->getMorphClass(),
            $request->user()->id,
            AccountType::PassengerWallet,
        );

        $query = $account->entries()
            ->with('journal')
            ->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to').' 23:59:59');
        }

        $entries = $query->paginate(20);

        return response()->json([
            'transactions' => LedgerEntryResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }
}
