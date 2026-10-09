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
use App\Models\Adjustment;
use App\Models\AuditLog;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAdjustmentController extends Controller
{
    public function __construct(private LedgerService $ledgerService) {}

    public function index(Request $request): JsonResponse
    {
        $query = Adjustment::with(['account', 'createdBy', 'approvedBy'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $adjustments = $query->paginate(20);

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
            'adjustment' => new AdjustmentResource($adjustment->load(['createdBy', 'account'])),
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

        DB::transaction(function () use ($request, $adjustment) {
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
        });

        return response()->json([
            'message' => 'Adjustment approved and posted.',
            'adjustment' => new AdjustmentResource($adjustment->fresh()->load(['createdBy', 'approvedBy', 'account'])),
        ]);
    }

    public function reject(RejectAdjustmentRequest $request, Adjustment $adjustment): JsonResponse
    {
        if (! $adjustment->isPending()) {
            return response()->json(['message' => 'Adjustment is not pending.'], 422);
        }

        $adjustment->update(['status' => AdjustmentStatus::Rejected]);

        AuditLog::record($adjustment, 'adjustment.rejected', $request->user(), null, [
            'reason' => $request->validated('reason'),
        ]);

        return response()->json(['message' => 'Adjustment rejected.']);
    }
}
