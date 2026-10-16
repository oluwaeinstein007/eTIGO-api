<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BankAccountNameMatch;
use App\Enums\BankAccountReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminPayoutAccountResource;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Notifications\PayoutAccountReviewedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Finance's queue of driver payout accounts: accounts whose name did not match
 * the driver's verified name wait here for review, and recently changed
 * accounts are on hold until their hold ends or finance releases it.
 */
class AdminPayoutAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'review_status' => ['sometimes', 'nullable', Rule::enum(BankAccountReviewStatus::class)],
            'name_match_status' => ['sometimes', 'nullable', Rule::enum(BankAccountNameMatch::class)],
            'on_hold' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = BankAccount::with(['driver.user', 'reviewedBy'])
            ->orderByRaw('CASE WHEN review_status = ? THEN 0 ELSE 1 END', [BankAccountReviewStatus::Pending->value])
            ->orderByDesc('created_at');

        if ($request->filled('review_status')) {
            $query->where('review_status', $request->input('review_status'));
        }

        if ($request->filled('name_match_status')) {
            $query->where('name_match_status', $request->input('name_match_status'));
        }

        if ($request->has('on_hold')) {
            $request->boolean('on_hold')
                ? $query->where('payouts_held_until', '>', now())
                : $query->where(fn ($q) => $q->whereNull('payouts_held_until')->orWhere('payouts_held_until', '<=', now()));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('account_name', 'ilike', "%{$search}%")
                    ->orWhereHas('driver.user', fn ($u) => $u->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        $accounts = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'payout_accounts' => AdminPayoutAccountResource::collection($accounts),
            'summary' => [
                'pending_review' => BankAccount::where('review_status', BankAccountReviewStatus::Pending)->count(),
                'on_hold' => BankAccount::where('payouts_held_until', '>', now())->count(),
            ],
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    public function show(BankAccount $bankAccount): JsonResponse
    {
        return response()->json([
            'payout_account' => new AdminPayoutAccountResource($bankAccount->load(['driver.user', 'reviewedBy'])),
        ]);
    }

    /**
     * Confirm the account belongs to the driver (for example after speaking to
     * them), so payouts can go to it. Also reinstates a rejected account.
     */
    public function approve(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->review($request, $bankAccount, BankAccountReviewStatus::Approved, $request->input('note'));
    }

    /**
     * Refuse payouts to this account. Any account can be rejected, including
     * one whose name matched, if finance believes it is not the driver's.
     */
    public function reject(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->review($request, $bankAccount, BankAccountReviewStatus::Rejected, $request->input('reason'));
    }

    /**
     * End the hold early, once the driver has confirmed they made the change.
     */
    public function releaseHold(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        $released = DB::transaction(function () use ($request, $bankAccount) {
            $account = BankAccount::lockForUpdate()->find($bankAccount->id);

            if (! $account->payouts_held_until?->isFuture()) {
                return null;
            }

            $heldUntil = $account->payouts_held_until->toIso8601String();
            $account->update(['payouts_held_until' => null]);

            AuditLog::record($account, 'bank_account.hold_released', $request->user(),
                ['payouts_held_until' => $heldUntil],
                ['payouts_held_until' => null, 'reason' => $request->input('reason')],
            );

            return $account;
        });

        if (! $released) {
            return response()->json(['message' => 'This bank account is not on hold.'], 422);
        }

        return response()->json([
            'message' => 'Payout hold released.',
            'payout_account' => new AdminPayoutAccountResource($released->load(['driver.user', 'reviewedBy'])),
        ]);
    }

    private function review(Request $request, BankAccount $bankAccount, BankAccountReviewStatus $outcome, ?string $note): JsonResponse
    {
        $reviewed = DB::transaction(function () use ($request, $bankAccount, $outcome, $note) {
            $account = BankAccount::lockForUpdate()->find($bankAccount->id);

            $allowed = $outcome === BankAccountReviewStatus::Approved
                ? in_array($account->review_status, [BankAccountReviewStatus::Pending, BankAccountReviewStatus::Rejected], true)
                : $account->review_status !== BankAccountReviewStatus::Rejected;

            if (! $allowed) {
                return null;
            }

            $previous = $account->review_status?->value;

            $account->update([
                'review_status' => $outcome,
                'review_note' => $note,
                'reviewed_by_admin_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            AuditLog::record($account, "bank_account.review_{$outcome->value}", $request->user(),
                ['review_status' => $previous],
                ['review_status' => $outcome->value, 'note' => $note],
            );

            return $account;
        });

        if (! $reviewed) {
            return response()->json([
                'message' => $outcome === BankAccountReviewStatus::Approved
                    ? 'Only a bank account waiting for review or rejected can be approved.'
                    : 'This bank account is already rejected.',
                'review_status' => $bankAccount->fresh()->review_status?->value,
            ], 422);
        }

        rescue(fn () => $reviewed->driver?->user?->notify(new PayoutAccountReviewedNotification($reviewed)));

        return response()->json([
            'message' => $outcome === BankAccountReviewStatus::Approved ? 'Bank account approved for payouts.' : 'Bank account rejected.',
            'payout_account' => new AdminPayoutAccountResource($reviewed->load(['driver.user', 'reviewedBy'])),
        ]);
    }
}
