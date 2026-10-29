<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountType;
use App\Enums\AdjustmentStatus;
use App\Enums\AdjustmentType;
use App\Enums\LedgerEntryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Wallet\RejectAdjustmentRequest;
use App\Http\Requests\Admin\Wallet\StoreAdjustmentRequest;
use App\Http\Resources\AdjustmentResource;
use App\Models\Account;
use App\Models\Adjustment;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminAdjustmentController extends Controller
{
    /**
     * The account and the admins involved. Owners are set with
     * Account::loadOwners (the account may be one of E-tiGo's own).
     */
    private const RELATIONS = ['account', 'createdBy', 'approvedBy', 'rejectedBy'];

    /**
     * @param  Adjustment|iterable<Adjustment>  $adjustments
     */
    private function withOwners(Adjustment|iterable $adjustments): Adjustment|iterable
    {
        $list = $adjustments instanceof Adjustment ? [$adjustments] : $adjustments;
        Account::loadOwners(collect($list)->pluck('account'));

        return $adjustments;
    }

    public function __construct(private LedgerService $ledgerService) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(AdjustmentStatus::class)],
            'type' => ['nullable', Rule::enum(AdjustmentType::class)],
            // passenger (wallets) or driver (earnings accounts).
            'owner_type' => ['nullable', 'in:passenger,driver'],
            'account_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Adjustment::with(self::RELATIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->input('account_id'), fn ($q, $accountId) => $q->where('account_id', $accountId))
            ->when($request->input('owner_type'), fn ($q, $ownerType) => $q->whereHas('account', fn ($a) => $a->whereIn(
                'type',
                $ownerType === 'driver'
                    ? [AccountType::DriverEarningsAvailable->value, AccountType::DriverEarningsPending->value]
                    : [AccountType::PassengerWallet->value],
            )))
            ->when($request->input('search'), function ($q, $search) {
                // Adjustment reference, reason, or the account owner's name, phone or reference.
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $person = fn ($u) => $u->where('first_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhereRaw("concat(first_name, ' ', last_name) ilike ?", [$like])
                    ->orWhere('phone', 'ilike', $like)
                    ->orWhere('reference_number', 'ilike', $like);

                $q->where(fn ($q) => $q->where('reference_number', 'ilike', $like)
                    ->orWhere('reason', 'ilike', $like)
                    ->orWhereHas('account', fn ($a) => $a->whereHasMorph('owner', [User::class, Driver::class], function ($owner, string $type) use ($person, $like) {
                        $type === Driver::class
                            ? $owner->where('reference_number', 'ilike', $like)->orWhereHas('user', $person)
                            : $person($owner);
                    })));
            });

        $adjustments = $query->paginate($this->perPage($request));
        $this->withOwners($adjustments->getCollection());

        return response()->json([
            'adjustments' => AdjustmentResource::collection($adjustments),
            'meta' => [
                'current_page' => $adjustments->currentPage(),
                'last_page' => $adjustments->lastPage(),
                'per_page' => $adjustments->perPage(),
                'total' => $adjustments->total(),
            ],
        ]);
    }

    public function show(Adjustment $adjustment): JsonResponse
    {
        return response()->json([
            'adjustment' => new AdjustmentResource($this->withOwners($adjustment->load(self::RELATIONS))),
        ]);
    }

    public function store(StoreAdjustmentRequest $request): JsonResponse
    {
        $adjustment = DB::transaction(function () use ($request) {
            $adjustment = Adjustment::create([
                'account_id' => $request->validated('account_id'),
                'type' => $request->validated('type'),
                'amount' => $request->validated('amount'),
                'reason' => $request->validated('reason'),
                'status' => AdjustmentStatus::Pending,
                'created_by_admin_id' => $request->user()->id,
            ]);

            AuditLog::record($adjustment, 'adjustment.created', $request->user());

            return $adjustment;
        });

        return response()->json([
            'message' => 'Adjustment created and pending approval.',
            'adjustment' => new AdjustmentResource($this->withOwners($adjustment->load(self::RELATIONS))),
        ], 201);
    }

    public function approve(Request $request, Adjustment $adjustment): JsonResponse
    {
        if (! $adjustment->isPending()) {
            return response()->json(['message' => 'Adjustment is not pending.'], 422);
        }

        if ($adjustment->created_by_admin_id === $request->user()->id) {
            return response()->json(['message' => 'Approver must differ from creator.'], 403);
        }

        try {
            $approved = DB::transaction(function () use ($request, $adjustment) {
                $adjustment = Adjustment::lockForUpdate()->find($adjustment->id);

                if (! $adjustment->isPending()) {
                    return false;
                }

                $systemAccount = $this->ledgerService->systemAccount(
                    $adjustment->type === AdjustmentType::Credit
                        ? AccountType::Refunds
                        : AccountType::PlatformCommission
                );

                $lines = $adjustment->type === AdjustmentType::Credit
                    ? [
                        ['account_id' => $systemAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $adjustment->amount],
                        ['account_id' => $adjustment->account_id, 'type' => LedgerEntryType::Credit->value, 'amount' => $adjustment->amount],
                    ]
                    : [
                        ['account_id' => $adjustment->account_id, 'type' => LedgerEntryType::Debit->value, 'amount' => $adjustment->amount],
                        ['account_id' => $systemAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $adjustment->amount],
                    ];

                $journal = $this->ledgerService->postJournal($lines, [
                    'description' => "Manual adjustment: {$adjustment->reason}",
                    'idempotency_key' => "adjustment-{$adjustment->id}",
                    'metadata' => ['adjustment_id' => $adjustment->id],
                ]);

                $adjustment->update([
                    'status' => AdjustmentStatus::Approved,
                    'approved_by_admin_id' => $request->user()->id,
                    'journal_id' => $journal->id,
                    'approved_at' => now(),
                ]);

                AuditLog::record($adjustment, 'adjustment.approved', $request->user());

                return true;
            });
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $approved) {
            return response()->json(['message' => 'Adjustment is not pending.'], 422);
        }

        return response()->json([
            'message' => 'Adjustment approved and posted.',
            'adjustment' => new AdjustmentResource($this->withOwners($adjustment->fresh()->load(self::RELATIONS))),
        ]);
    }

    public function reject(RejectAdjustmentRequest $request, Adjustment $adjustment): JsonResponse
    {
        $updated = DB::transaction(function () use ($request, $adjustment) {
            $updated = Adjustment::whereKey($adjustment->id)
                ->where('status', AdjustmentStatus::Pending)
                ->update([
                    'status' => AdjustmentStatus::Rejected,
                    'rejected_by_admin_id' => $request->user()->id,
                    'rejected_at' => now(),
                    'rejection_reason' => $request->validated('reason'),
                ]);

            if ($updated > 0) {
                AuditLog::record($adjustment, 'adjustment.rejected', $request->user(), null, [
                    'reason' => $request->validated('reason'),
                ]);
            }

            return $updated;
        });

        if ($updated === 0) {
            return response()->json(['message' => 'Adjustment is not pending.'], 422);
        }

        return response()->json([
            'message' => 'Adjustment rejected.',
            'adjustment' => new AdjustmentResource($this->withOwners($adjustment->fresh()->load(self::RELATIONS))),
        ]);
    }
}
