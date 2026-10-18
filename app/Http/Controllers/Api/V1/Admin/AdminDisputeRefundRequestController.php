<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Enums\DisputeRefundRequestStatus;
use App\Exceptions\AccessDeniedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeRefundRequestResource;
use App\Http\Resources\DisputeResource;
use App\Models\DisputeRefundRequest;
use App\Services\DisputeAccess;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dispute refunds waiting for a second admin (BE2-DISP-07): above the
 * requester's tier limit (a higher tier approves) or back to the card
 * (Finance approves). Nobody approves their own.
 */
class AdminDisputeRefundRequestController extends Controller
{
    public function __construct(
        private DisputeService $disputes,
        private DisputeAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,approved,rejected,cancelled'],
        ]);

        $admin = $request->user();

        $requests = DisputeRefundRequest::with(['dispute', 'requestedBy', 'reviewedBy'])
            ->where('status', $request->input('status', DisputeRefundRequestStatus::Pending->value))
            // Finance sees every request; dispute roles only cases they can see.
            ->when($admin->admin_role !== AdminRole::Finance, fn ($q) => $q->whereHas('dispute', fn ($q) => $this->access->scopeVisible($q, $admin)))
            ->latest()
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json([
            'refund_requests' => collect($requests->items())->map(fn (DisputeRefundRequest $r) => [
                ...(new DisputeRefundRequestResource($r))->resolve($request),
                'can_approve' => $r->isPending() && $this->access->approvalDenial($admin, $r) === null,
                'can_withdraw' => $r->isPending() && $r->requested_by_admin_id === $admin->id,
            ])->all(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function approve(Request $request, DisputeRefundRequest $refundRequest): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->disputes->approveRefund($refundRequest, $request->user(), $validated['notes'] ?? null);
        } catch (AccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Refund approved and paid. The dispute is resolved.',
            'refund_request' => new DisputeRefundRequestResource($result['refund_request']->load(['requestedBy', 'reviewedBy'])),
            'dispute' => new DisputeResource($result['dispute']->load(['reportedBy', 'resolvedBy', 'ride'])),
            'driver_clawback_kobo' => $result['clawback_kobo'],
        ]);
    }

    public function reject(Request $request, DisputeRefundRequest $refundRequest): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $result = $this->disputes->rejectRefund($refundRequest, $request->user(), $validated['notes']);
        } catch (AccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $result->status === DisputeRefundRequestStatus::Cancelled
                ? 'Refund request withdrawn. The case is still open.'
                : 'Refund request rejected. The case is still open.',
            'refund_request' => new DisputeRefundRequestResource($result->load(['requestedBy', 'reviewedBy'])),
        ]);
    }
}
