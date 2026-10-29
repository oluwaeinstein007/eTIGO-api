<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Enums\HoldStatus;
use App\Enums\TransactionType;
use App\Enums\WalletTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Wallet\FreezeWalletRequest;
use App\Http\Resources\AccountResource;
use App\Http\Resources\HoldResource;
use App\Http\Resources\LedgerEntryResource;
use App\Models\Account;
use App\Models\Adjustment;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminWalletController extends Controller
{
    /**
     * Status of a wallet transaction as the dashboard shows it: everything on
     * the ledger has gone through; top-ups still waiting for or refused by the
     * card provider are pending or failed.
     */
    private const TRANSACTION_STATUSES = ['success', 'pending', 'failed'];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'min_balance' => ['nullable', 'integer', 'min:0'],
            'max_balance' => ['nullable', 'integer', 'min:0'],
        ]);

        $query = Account::where('type', AccountType::PassengerWallet)
            ->with('owner')
            ->withSum(['holds as held_total' => fn ($q) => $q->where('status', HoldStatus::Active)], 'amount')
            ->withMax('entries as last_activity_at', 'created_at');

        if ($request->filled('search')) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->input('search')).'%';
            $query->whereHasMorph('owner', [User::class], function ($q) use ($like) {
                $q->where(function ($q) use ($like) {
                    $q->where('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", [$like])
                        ->orWhere('email', 'ilike', $like)
                        ->orWhere('phone', 'ilike', $like)
                        ->orWhere('reference_number', 'ilike', $like);
                });
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

        $accounts = $query->orderByDesc('created_at')->paginate($this->perPage($request));

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

    public function show(Request $request, Account $account): JsonResponse
    {
        $this->ensurePassengerWallet($account);

        $account->load(['owner', 'frozenBy'])
            ->loadMax('entries as last_activity_at', 'created_at');

        $activeHolds = $account->holds()
            ->active()
            ->with('ride.vehicleClass')
            ->orderByDesc('created_at')
            ->get();

        $recentEntries = $account->entries()
            ->with('journal')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'wallet' => new AccountResource($account),
            'active_holds' => HoldResource::collection($activeHolds),
            'recent_transactions' => LedgerEntryResource::collection($recentEntries),
            // What the signed-in admin may do here; the server checks again on each action.
            'permissions' => [
                'can_freeze' => $this->canFreeze($request->user()),
            ],
        ]);
    }

    /**
     * Every money movement on the wallet plus top-ups that are still pending or
     * failed, newest first.
     */
    public function transactions(Request $request, Account $account): JsonResponse
    {
        $this->ensurePassengerWallet($account);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'status' => ['nullable', Rule::in(self::TRANSACTION_STATUSES)],
            'direction' => ['nullable', Rule::in(['credit', 'debit'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $ledger = DB::table('ledger_entries as e')
            ->join('journals as j', 'j.id', '=', 'e.journal_id')
            ->where('e.account_id', $account->id)
            ->selectRaw("e.id::text AS id, 'ledger' AS source, j.type AS type, e.type AS direction, e.amount, 'success' AS status, j.reference, j.description, j.metadata->>'ride_id' AS ride_id, e.created_at");

        $attempts = DB::table('wallet_transactions as w')
            ->where('w.account_id', $account->id)
            ->where('w.status', '!=', WalletTransactionStatus::Completed->value)
            ->selectRaw(
                "w.id::text AS id, 'top_up_attempt' AS source, ?::text AS type, 'credit' AS direction, w.amount, CASE WHEN w.status = ? THEN 'pending' ELSE 'failed' END AS status, w.reference, 'Wallet top-up' AS description, NULL AS ride_id, w.created_at",
                [TransactionType::TopUp->value, WalletTransactionStatus::Pending->value],
            );

        $timezone = config('wallet.timezone', 'Africa/Lagos');

        $transactions = DB::query()
            ->fromSub($ledger->unionAll($attempts), 't')
            ->leftJoin('rides as r', DB::raw('r.id::text'), '=', 't.ride_id')
            ->select(['t.*', 'r.reference_number as ride_reference_number'])
            ->when($request->input('type'), fn (QueryBuilder $q, $type) => $q->where('t.type', $type))
            ->when($request->input('status'), fn (QueryBuilder $q, $status) => $q->where('t.status', $status))
            ->when($request->input('direction'), fn (QueryBuilder $q, $direction) => $q->where('t.direction', $direction))
            ->when($request->input('date_from'), fn (QueryBuilder $q, $date) => $q->where(
                't.created_at',
                '>=',
                CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->utc(),
            ))
            ->when($request->input('date_to'), fn (QueryBuilder $q, $date) => $q->where(
                't.created_at',
                '<',
                CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->addDay()->utc(),
            ))
            ->when($request->input('search'), function (QueryBuilder $q, $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $q->where(fn (QueryBuilder $q) => $q->where('t.reference', 'ilike', $like)
                    ->orWhere('t.description', 'ilike', $like)
                    ->orWhere('r.reference_number', 'ilike', $like));
            })
            ->orderByDesc('t.created_at')
            ->orderByDesc('t.id')
            ->paginate($this->perPage($request));

        return response()->json([
            'transactions' => collect($transactions->items())->map(function ($row) {
                $type = $row->type ? TransactionType::tryFrom($row->type) : null;

                return [
                    'id' => $row->id,
                    'source' => $row->source,
                    'type' => $type ? ['value' => $type->value, 'label' => $type->label()] : null,
                    'direction' => $row->direction,
                    'amount' => (int) $row->amount,
                    'status' => $row->status,
                    'reference' => $row->reference,
                    'description' => $row->description,
                    'ride_id' => $row->ride_id,
                    'ride_reference_number' => $row->ride_reference_number,
                    'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
                ];
            }),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Changes made to the wallet by staff: freezes, unfreezes and manual
     * adjustments, newest first.
     */
    public function auditTrail(Request $request, Account $account): JsonResponse
    {
        $this->ensurePassengerWallet($account);

        $adjustmentIds = Adjustment::where('account_id', $account->id)->pluck('id');

        $logs = AuditLog::query()
            ->where(function ($q) use ($account, $adjustmentIds) {
                $q->where(fn ($q) => $q->where('auditable_type', $account->getMorphClass())->where('auditable_id', $account->id))
                    ->orWhere(fn ($q) => $q->where('auditable_type', (new Adjustment)->getMorphClass())->whereIn('auditable_id', $adjustmentIds));
            })
            ->with('actor')
            // Ids are time-ordered, which settles entries made in the same second.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $adjustments = Adjustment::whereIn('id', collect($logs->items())->pluck('auditable_id'))->get()->keyBy('id');

        return response()->json([
            'entries' => collect($logs->items())->map(function (AuditLog $log) use ($adjustments) {
                $adjustment = $adjustments->get($log->auditable_id);

                return [
                    'id' => $log->id,
                    'event' => $log->event,
                    'description' => $this->describeAuditEvent($log->event),
                    'reason' => $log->new_values['reason'] ?? $adjustment?->reason,
                    'amount' => $adjustment?->amount,
                    // credit (added to the wallet) or debit (taken from it); null for non-adjustment events.
                    'direction' => $adjustment?->type->value,
                    'adjustment' => $adjustment ? [
                        'id' => $adjustment->id,
                        'reference_number' => $adjustment->reference_number,
                        'type' => $adjustment->type->value,
                        'status' => $adjustment->status->value,
                    ] : null,
                    'actor' => $log->actor ? [
                        'id' => $log->actor->id,
                        'name' => trim($log->actor->first_name.' '.$log->actor->last_name),
                        'role' => $log->actor->admin_role?->value ?? $log->actor->type?->value,
                    ] : null,
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            }),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
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

        DB::transaction(function () use ($request, $account, $oldStatus) {
            $account->update([
                'status' => AccountStatus::Frozen,
                'frozen_at' => now(),
                'frozen_reason' => $request->validated('reason'),
                'frozen_by_admin_id' => $request->user()->id,
            ]);

            AuditLog::record(
                $account,
                'wallet.frozen',
                $request->user(),
                ['status' => $oldStatus->value],
                ['status' => AccountStatus::Frozen->value, 'reason' => $request->validated('reason')],
            );
        });

        return response()->json([
            'message' => 'Wallet frozen successfully.',
            'wallet' => new AccountResource($account->load(['owner', 'frozenBy'])),
        ]);
    }

    public function unfreeze(FreezeWalletRequest $request, Account $account): JsonResponse
    {
        if ($account->type !== AccountType::PassengerWallet) {
            return response()->json(['message' => 'Only passenger wallets can be unfrozen.'], 422);
        }

        if (! $account->isFrozen()) {
            return response()->json(['message' => 'Wallet is not frozen.'], 422);
        }

        DB::transaction(function () use ($request, $account) {
            $account->update([
                'status' => AccountStatus::Active,
                'frozen_at' => null,
                'frozen_reason' => null,
                'frozen_by_admin_id' => null,
            ]);

            AuditLog::record(
                $account,
                'wallet.unfrozen',
                $request->user(),
                ['status' => AccountStatus::Frozen->value],
                ['status' => AccountStatus::Active->value, 'reason' => $request->validated('reason')],
            );
        });

        return response()->json([
            'message' => 'Wallet unfrozen successfully.',
            'wallet' => new AccountResource($account->load('owner')),
        ]);
    }

    private function ensurePassengerWallet(Account $account): void
    {
        // Driver and system ledgers have their own screens.
        abort_unless($account->type === AccountType::PassengerWallet, 404, 'Wallet not found.');
    }

    private function canFreeze(?User $user): bool
    {
        return $user !== null
            && ($user->hasAdminRole(AdminRole::Finance) || $user->hasAdminRole(AdminRole::SuperAdmin));
    }

    private function describeAuditEvent(string $event): string
    {
        return match ($event) {
            'wallet.frozen' => 'Wallet frozen',
            'wallet.unfrozen' => 'Wallet unfrozen',
            'adjustment.created' => 'Manual adjustment requested',
            'adjustment.approved' => 'Manual adjustment approved',
            'adjustment.rejected' => 'Manual adjustment rejected',
            default => ucfirst(str_replace(['.', '_'], ' ', $event)),
        };
    }
}
