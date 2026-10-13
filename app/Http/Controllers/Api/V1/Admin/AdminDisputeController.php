<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\ResolveDisputeFormRequest;
use App\Http\Resources\DisputeResource;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Notifications\DisputeResolvedNotification;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDisputeController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Dispute::with(['reportedBy', 'resolvedBy', 'ride.city', 'ride.passenger', 'ride.driver']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('ride_id')) {
            $query->where('ride_id', $request->input('ride_id'));
        }

        if ($request->filled('city_id')) {
            $query->whereHas('ride', fn ($q) => $q->where('city_id', $request->input('city_id')));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ilike', "%{$search}%")
                    ->orWhereHas('reportedBy', function ($q) use ($search) {
                        $q->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $disputes = $query->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json([
            'disputes' => DisputeResource::collection($disputes),
            'meta' => [
                'current_page' => $disputes->currentPage(),
                'last_page' => $disputes->lastPage(),
                'per_page' => $disputes->perPage(),
                'total' => $disputes->total(),
            ],
        ]);
    }

    public function show(Dispute $dispute): JsonResponse
    {
        $dispute->load([
            'reportedBy',
            'resolvedBy',
            'ride.passenger',
            'ride.driver.driver.vehicle',
            'ride.vehicleClass',
            'ride.city',
            'ride.payment.paymentMethod',
            'notes.admin',
        ]);

        return response()->json([
            'dispute' => new DisputeResource($dispute),
        ]);
    }

    public function updateStatus(Request $request, Dispute $dispute): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'in:under_review'],
        ]);

        if ($dispute->status !== DisputeStatus::Open) {
            return response()->json([
                'message' => 'Only open disputes can be moved to under review.',
            ], 422);
        }

        $admin = $request->user();

        $updated = Dispute::where('id', $dispute->id)
            ->where('status', DisputeStatus::Open)
            ->update(['status' => DisputeStatus::UnderReview]);

        if ($updated === 0) {
            return response()->json([
                'message' => 'Dispute status has already changed. Please refresh and try again.',
            ], 422);
        }

        $dispute->refresh();

        AuditLog::record($dispute, 'dispute_status_changed', $admin, [
            'status' => DisputeStatus::Open->value,
        ], [
            'status' => DisputeStatus::UnderReview->value,
        ]);

        $dispute->load(['reportedBy', 'resolvedBy', 'ride']);
        $dispute->reportedBy?->notify(new DisputeResolvedNotification($dispute));

        return response()->json([
            'message' => 'Dispute moved to under review.',
            'dispute' => new DisputeResource($dispute),
        ]);
    }

    public function resolve(ResolveDisputeFormRequest $request, Dispute $dispute): JsonResponse
    {
        $admin = $request->user();
        $oldStatus = $dispute->status;
        $refundAmount = $request->validated('refund_amount');

        DB::transaction(function () use ($dispute, $request, $admin, $refundAmount) {
            $updateData = [
                'status' => $request->validated('status'),
                'resolution_notes' => $request->validated('resolution_notes'),
                'resolved_by_admin_id' => $admin->id,
                'resolved_at' => now(),
            ];

            if ($refundAmount) {
                $payment = $dispute->ride->payment;
                $this->paymentService->refundPayment($payment, (float) $refundAmount);

                $updateData['refund_amount'] = $refundAmount;
                $updateData['refund_currency'] = $payment->currency;
            }

            $dispute->update($updateData);
        });

        $auditNewState = [
            'status' => $request->validated('status'),
            'resolution_notes' => $request->validated('resolution_notes'),
        ];

        if ($refundAmount) {
            $auditNewState['refund_amount'] = $refundAmount;
        }

        AuditLog::record($dispute, 'dispute_resolved', $admin, [
            'status' => $oldStatus->value,
        ], $auditNewState);

        $dispute->load(['reportedBy', 'resolvedBy', 'ride']);
        $dispute->reportedBy?->notify(new DisputeResolvedNotification($dispute));

        $message = 'Dispute resolved successfully.';
        if ($refundAmount) {
            $message = "Dispute resolved successfully. Refund of {$dispute->refund_currency} ".number_format($refundAmount, 2).' issued.';
        }

        return response()->json([
            'message' => $message,
            'dispute' => new DisputeResource($dispute),
        ]);
    }
}
