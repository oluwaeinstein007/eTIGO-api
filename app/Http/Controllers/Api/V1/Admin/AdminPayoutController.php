<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\NotificationType;
use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutResource;
use App\Jobs\ProcessPayoutTransferJob;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Notifications\PayoutStatusNotification;
use App\Services\PayoutReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(PayoutStatus::class)],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        $query = Payout::with(['driver.user', 'bankAccount', 'approvedBy'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('driver.user', function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('from')) {
            $query->where('requested_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('requested_at', '<=', $request->input('to').' 23:59:59');
        }

        $payouts = $query->paginate(20);

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

    public function approve(Request $request, Payout $payout): JsonResponse
    {
        $approved = DB::transaction(function () use ($request, $payout) {
            $payout = Payout::lockForUpdate()->find($payout->id);

            if (! $payout->isPending()) {
                return null;
            }

            $payout->update([
                'status' => PayoutStatus::Approved,
                'approved_at' => now(),
                'approved_by_admin_id' => $request->user()->id,
            ]);

            AuditLog::record($payout, 'payout.approved', $request->user());

            return $payout;
        });

        if (! $approved) {
            return response()->json(['message' => 'Payout is not in requested status.'], 422);
        }

        ProcessPayoutTransferJob::dispatch($approved->id);

        rescue(fn () => $approved->driver?->user?->notify(new PayoutStatusNotification(
            $approved->amount,
            NotificationType::PayoutApproved,
        )));

        return response()->json([
            'message' => 'Payout approved and transfer initiated.',
            'payout' => new PayoutResource($approved->fresh()->load(['bankAccount', 'driver.user'])),
        ]);
    }

    public function reject(Request $request, Payout $payout): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:10']]);

        $rejected = DB::transaction(function () use ($request, $payout) {
            $payout = Payout::lockForUpdate()->find($payout->id);

            if (! $payout->isPending()) {
                return null;
            }

            $payout->update([
                'status' => PayoutStatus::Rejected,
                'failure_reason' => $request->input('reason'),
            ]);

            AuditLog::record($payout, 'payout.rejected', $request->user(), null, [
                'reason' => $request->input('reason'),
            ]);

            return $payout;
        });

        if (! $rejected) {
            return response()->json(['message' => 'Payout is not in requested status.'], 422);
        }

        rescue(fn () => $rejected->driver?->user?->notify(new PayoutStatusNotification(
            $rejected->amount,
            NotificationType::PayoutRejected,
            $request->input('reason'),
        )));

        return response()->json(['message' => 'Payout rejected.']);
    }

    public function retry(Request $request, Payout $payout): JsonResponse
    {
        $retried = DB::transaction(function () use ($request, $payout) {
            $payout = Payout::lockForUpdate()->find($payout->id);

            // Failed (the transfer was refused) and reversed (the bank sent it
            // back) both mean the money is back in the driver's balance.
            if (! in_array($payout->status, [PayoutStatus::Failed, PayoutStatus::Reversed], true)) {
                return null;
            }

            $payout->update([
                'status' => PayoutStatus::Approved,
                'failure_reason' => null,
                'approved_at' => now(),
                'approved_by_admin_id' => $request->user()->id,
            ]);

            AuditLog::record($payout, 'payout.retried', $request->user());

            return $payout;
        });

        if (! $retried) {
            return response()->json(['message' => 'Only failed or reversed payouts can be retried.'], 422);
        }

        ProcessPayoutTransferJob::dispatch($retried->id);

        return response()->json(['message' => 'Payout retry initiated.']);
    }

    /**
     * Finance's "check status" action for a payout whose transfer outcome is
     * unknown: asks Paystack about the transfer reference and settles it.
     */
    public function verify(Request $request, Payout $payout, PayoutReconciliationService $reconciler): JsonResponse
    {
        if (! in_array($payout->status, [PayoutStatus::Approved, PayoutStatus::Processing], true)) {
            return response()->json([
                'message' => 'Only approved or processing payouts can be checked with the payment provider.',
                'current_status' => $payout->status->value,
            ], 422);
        }

        try {
            $result = $reconciler->reconcile($payout);
        } catch (\Throwable $e) {
            if (! PayoutReconciliationService::isProviderUnavailable($e)) {
                throw $e;
            }

            return response()->json(['message' => 'Could not reach the payment provider. Try again shortly.'], 502);
        }

        AuditLog::record($payout, 'payout.verified', $request->user(), null, $result);

        return response()->json([
            'message' => match ($result['outcome']) {
                PayoutReconciliationService::OUTCOME_PAID => 'The transfer went through; the payout is now paid.',
                PayoutReconciliationService::OUTCOME_FAILED => 'The transfer failed; the money was returned to the driver. You can retry the payout.',
                PayoutReconciliationService::OUTCOME_PROCESSING => 'The transfer is still being processed by the payment provider.',
                PayoutReconciliationService::OUTCOME_RESENT => 'The transfer was never created; it has been sent again.',
                default => 'The transfer status could not be confirmed; it will be checked again automatically.',
            },
            'outcome' => $result['outcome'],
            'provider_status' => $result['provider_status'],
            'payout' => new PayoutResource($payout->fresh()->load(['bankAccount', 'driver.user'])),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        return response()->streamDownload(function () use ($request) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Payout ID', 'Driver', 'Bank', 'Account', 'Amount (kobo)', 'Status', 'Requested At', 'Paid At']);

            Payout::with(['driver.user', 'bankAccount'])
                ->whereBetween('requested_at', [$request->input('from'), $request->input('to').' 23:59:59'])
                ->orderBy('requested_at')
                ->orderBy('id')
                ->chunk(100, function ($payouts) use ($handle) {
                    foreach ($payouts as $payout) {
                        fputcsv($handle, [
                            $payout->id,
                            $payout->driver?->user?->first_name.' '.$payout->driver?->user?->last_name,
                            $payout->bankAccount?->bank_code,
                            $payout->bankAccount?->maskedAccountNumber(),
                            $payout->amount,
                            $payout->status->value,
                            $payout->requested_at?->toIso8601String(),
                            $payout->paid_at?->toIso8601String(),
                        ]);
                    }
                });

            fclose($handle);
        }, 'payouts-export-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
