<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\LedgerEntryResource;
use App\Http\Resources\PayoutResource;
use App\Models\Account;
use App\Models\Driver;
use App\Models\Payout;
use App\Support\LagosDay;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDriverLedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $query = Account::where('type', AccountType::DriverEarningsAvailable)
            ->with(['owner' => fn (MorphTo $owner) => $owner->morphWith([Driver::class => ['user']])]);

        if ($request->filled('search')) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->input('search')).'%';
            $query->whereHasMorph('owner', [Driver::class], function ($q) use ($like) {
                $q->where('reference_number', 'ilike', $like)
                    ->orWhereHas('user', function ($uq) use ($like) {
                        $uq->where('first_name', 'ilike', $like)
                            ->orWhere('last_name', 'ilike', $like)
                            ->orWhereRaw("concat(first_name, ' ', last_name) ilike ?", [$like])
                            ->orWhere('email', 'ilike', $like)
                            ->orWhere('phone', 'ilike', $like);
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

    /**
     * The ledger with its latest 20 entries and 10 payouts. Older ones are
     * paged through with /entries and /payouts.
     */
    public function show(Account $account): JsonResponse
    {
        $this->ensureDriverAccount($account);

        $account->load('owner.user');

        $recentEntries = $account->entries()
            ->with('journal')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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

    /**
     * Every entry on the driver's account, newest first.
     */
    public function entries(Request $request, Account $account): JsonResponse
    {
        $this->ensureDriverAccount($account);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', 'in:debit,credit'],
        ]);

        $query = $account->entries()
            ->with('journal')
            ->when($request->input('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        LagosDay::filter($query, 'created_at', $request->input('from'), $request->input('to'));

        $entries = $query->paginate($this->perPage($request));

        return response()->json([
            'entries' => LedgerEntryResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /**
     * Every payout the driver has asked for, newest first.
     */
    public function payouts(Request $request, Account $account): JsonResponse
    {
        $this->ensureDriverAccount($account);

        $payouts = Payout::where('driver_id', $account->owner_id)
            ->with(['bankAccount', 'approvedBy', 'rejectedBy'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'payouts' => PayoutResource::collection($payouts),
            'meta' => [
                'current_page' => $payouts->currentPage(),
                'last_page' => $payouts->lastPage(),
                'per_page' => $payouts->perPage(),
                'total' => $payouts->total(),
            ],
        ]);
    }

    private function ensureDriverAccount(Account $account): void
    {
        abort_unless($account->type->isDriverAccount(), 404, 'Driver ledger not found.');
    }
}
