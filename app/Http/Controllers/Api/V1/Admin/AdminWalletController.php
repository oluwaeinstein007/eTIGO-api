<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Wallet\FreezeWalletRequest;
use App\Http\Resources\AccountResource;
use App\Http\Resources\HoldResource;
use App\Http\Resources\LedgerEntryResource;
use App\Models\Account;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Account::where('type', AccountType::PassengerWallet)
            ->with('owner');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHasMorph('owner', ['App\\Models\\User'], function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('min_balance')) {
            $query->where('balance', '>=', (int) $request->input('min_balance'));
        }

        if ($request->filled('max_balance')) {
            $query->where('balance', '<=', (int) $request->input('max_balance'));
        }

        $accounts = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'wallets' => AccountResource::collection($accounts),
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
        $activeHolds = $account->holds()->active()->get();
        $recentEntries = $account->entries()
            ->with('journal')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'wallet' => new AccountResource($account),
            'active_holds' => HoldResource::collection($activeHolds),
            'recent_transactions' => LedgerEntryResource::collection($recentEntries),
        ]);
    }

    public function freeze(FreezeWalletRequest $request, Account $account): JsonResponse
    {
        if ($account->type !== AccountType::PassengerWallet) {
            return response()->json(['message' => 'Only passenger wallets can be frozen.'], 422);
        }

        if ($account->isFrozen()) {
            return response()->json(['message' => 'Wallet is already frozen.'], 422);
        }

        $oldStatus = $account->status;
        $account->update(['status' => AccountStatus::Frozen]);

        AuditLog::record(
            $account,
            'wallet.frozen',
            $request->user(),
            ['status' => $oldStatus->value],
            ['status' => AccountStatus::Frozen->value, 'reason' => $request->validated('reason')],
        );

        return response()->json(['message' => 'Wallet frozen successfully.']);
    }

    public function unfreeze(FreezeWalletRequest $request, Account $account): JsonResponse
    {
        if ($account->type !== AccountType::PassengerWallet) {
            return response()->json(['message' => 'Only passenger wallets can be unfrozen.'], 422);
        }

        if (! $account->isFrozen()) {
            return response()->json(['message' => 'Wallet is not frozen.'], 422);
        }

        $account->update(['status' => AccountStatus::Active]);

        AuditLog::record(
            $account,
            'wallet.unfrozen',
            $request->user(),
            ['status' => AccountStatus::Frozen->value],
            ['status' => AccountStatus::Active->value, 'reason' => $request->validated('reason')],
        );

        return response()->json(['message' => 'Wallet unfrozen successfully.']);
    }
}
