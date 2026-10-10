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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
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
        if (! $payout->isPending()) {
            return response()->json(['message' => 'Payout is not in requested status.'], 422);
        }

        DB::transaction(function () use ($request, $payout) {
            $payout->update([
                'status' => PayoutStatus::Approved,
                'approved_at' => now(),
                'approved_by_admin_id' => $request->user()->id,
            ]);

            AuditLog::record($payout, 'payout.approved', $request->user());
        });

        ProcessPayoutTransferJob::dispatch($payout->id);

        $payout->driver?->user?->notify(new PayoutStatusNotification(
            $payout->amount,
            NotificationType::PayoutApproved,
        ));

        return response()->json([
            'message' => 'Payout approved and transfer initiated.',
            'payout' => new PayoutResource($payout->fresh()->load(['bankAccount', 'driver.user'])),
        ]);
    }

    public function reject(Request $request, Payout $payout): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:10']]);

        if (! $payout->isPending()) {
            return response()->json(['message' => 'Payout is not in requested status.'], 422);
        }

        $payout->update([
            'status' => PayoutStatus::Rejected,
            'failure_reason' => $request->input('reason'),
        ]);

        AuditLog::record($payout, 'payout.rejected', $request->user(), null, [
            'reason' => $request->input('reason'),
        ]);

        return response()->json(['message' => 'Payout rejected.']);
    }

    public function retry(Request $request, Payout $payout): JsonResponse
    {
        if ($payout->status !== PayoutStatus::Failed) {
            return response()->json(['message' => 'Only failed payouts can be retried.'], 422);
        }

        $payout->update([
            'status' => PayoutStatus::Approved,
            'failure_reason' => null,
            'approved_at' => now(),
            'approved_by_admin_id' => $request->user()->id,
        ]);

        ProcessPayoutTransferJob::dispatch($payout->id);

        AuditLog::record($payout, 'payout.retried', $request->user());

        return response()->json(['message' => 'Payout retry initiated.']);
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
