<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\LedgerEntryResource;
use App\Http\Resources\PayoutResource;
use App\Models\Account;
use App\Models\Payout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDriverLedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Account::where('type', AccountType::DriverEarningsAvailable)
            ->with('owner');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHasMorph('owner', ['App\\Models\\Driver'], function ($q) use ($search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");
                });
            });
        }

        $accounts = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'driver_ledgers' => AccountResource::collection($accounts),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    public function show(Account $account): JsonResponse
    {
        $account->load('owner');

        $recentEntries = $account->entries()
            ->with('journal')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $payouts = Payout::where('driver_id', $account->owner_id)
            ->with('bankAccount')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'driver_ledger' => new AccountResource($account),
            'recent_transactions' => LedgerEntryResource::collection($recentEntries),
            'recent_payouts' => PayoutResource::collection($payouts),
        ]);
    }
}
