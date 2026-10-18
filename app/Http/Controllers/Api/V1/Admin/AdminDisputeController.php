<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DisputeRefundMethod;
use App\Enums\DisputeStatus;
use App\Exceptions\AccessDeniedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\ResolveDisputeFormRequest;
use App\Http\Resources\DisputeRefundRequestResource;
use App\Http\Resources\DisputeResource;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\DisputeRefundRequest;
use App\Models\User;
use App\Services\DisputeAccess;
use App\Services\DisputeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dispute queue (OQ-21): support tiers 1–3 and the Safety team. Each
 * admin sees the queues their role covers and can work cases at their tier
 * or below; evidence only where NF-12 allows, every view audit-logged.
 */
class AdminDisputeController extends Controller
{
    /** SQL for the next target an open case has to meet (Dispute::nextDueAt). */
    private const NEXT_DUE = 'COALESCE(CASE WHEN first_responded_at IS NULL THEN first_response_due_at END, resolution_due_at)';

    public function __construct(
        private DisputeService $disputes,
        private DisputeAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'queue' => ['sometimes', 'string', 'in:support,safety'],
            'tier' => ['sometimes', 'integer', 'between:1,3'],
            'assigned' => ['sometimes', 'string', 'regex:/^(me|unassigned|[0-9a-fA-F-]{36})$/'],
            'ride_id' => ['sometimes', 'uuid'],
            'city_id' => ['sometimes', 'uuid'],
            'sla' => ['sometimes', 'string', 'in:breached,at_risk'],
            'sort' => ['sometimes', 'string', 'in:newest,due'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $admin = $request->user();
        $query = $this->access->scopeVisible(Dispute::query(), $admin)
            ->with(['reportedBy', 'resolvedBy', 'assignedAdmin', 'ride.city', 'ride.passenger', 'ride.driver'])
            ->withCount('evidence');

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', (string) $request->input('status')));
        }

        if ($request->has('open')) {
            $request->boolean('open') ? $query->open() : $query->whereNotIn('status', [DisputeStatus::Open, DisputeStatus::UnderReview]);
        }

        foreach (['category', 'ride_id', 'queue'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('tier')) {
            $query->where('escalation_tier', $request->integer('tier'));
        }

        if ($request->filled('assigned')) {
            match ($request->input('assigned')) {
                'me' => $query->where('assigned_admin_id', $admin->id),
                'unassigned' => $query->whereNull('assigned_admin_id'),
                default => $query->where('assigned_admin_id', $request->input('assigned')),
            };
        }

        if ($request->input('sla') === 'breached') {
            $this->wherePastDue($query->open());
        } elseif ($request->input('sla') === 'at_risk') {
            // Less than a quarter of the time to the next target left.
            $query->open()->whereRaw(
                '('.self::NEXT_DUE.') > ? AND (('.self::NEXT_DUE.') - ?::timestamp) * 4 < (('.self::NEXT_DUE.') - COALESCE(sla_started_at, created_at))',
                [now(), now()],
            );
        }

        if ($request->has('appealed')) {
            $request->boolean('appealed') ? $query->whereNotNull('appealed_at') : $query->whereNull('appealed_at');
        }

        if ($request->boolean('pending_refund')) {
            $query->whereHas('pendingRefundRequest');
        }

        if ($request->filled('city_id')) {
            $query->whereHas('ride', fn ($q) => $q->where('city_id', $request->input('city_id')));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ilike', "%{$search}%")
                    ->orWhere('reference_number', 'ilike', "%{$search}%")
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

        if ($request->input('sort') === 'due') {
            // Open cases first, soonest target first.
            $query->orderByRaw("CASE WHEN status IN ('open', 'under_review') THEN 0 ELSE 1 END")
                ->orderByRaw('('.self::NEXT_DUE.') ASC NULLS LAST')
                ->orderByDesc('created_at');
        } else {
            $query->orderByDesc('created_at');
        }

        $disputes = $query->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json([
            'disputes' => DisputeResource::collection($disputes),
            'summary' => $this->summary($admin),
            'meta' => [
                'current_page' => $disputes->currentPage(),
                'last_page' => $disputes->lastPage(),
                'per_page' => $disputes->perPage(),
                'total' => $disputes->total(),
            ],
        ]);
    }

    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        $admin = $request->user();

        if (! $this->access->canView($admin, $dispute)) {
            return response()->json(['message' => 'Safety cases are only visible to the Safety team.'], 403);
        }

        $dispute->load([
            'reportedBy',
            'resolvedBy',
            'assignedAdmin',
            'linkedDispute',
            'pendingRefundRequest.requestedBy',
            'refundRequests.requestedBy',
            'refundRequests.reviewedBy',
            'ride.passenger',
            'ride.driver.driver.vehicle',
            'ride.vehicleClass',
            'ride.city',
            'ride.payment.paymentMethod',
            'ride.scheduledRide',
            'notes.admin',
            'evidence',
        ]);

        $showEvidence = $this->access->canViewEvidence($admin, $dispute);

        if ($showEvidence && $dispute->evidence->isNotEmpty()) {
            AuditLog::record($dispute, 'dispute_evidence_viewed', $admin, null, [
                'evidence_ids' => $dispute->evidence->pluck('id')->all(),
            ]);
        }

        return response()->json([
            'dispute' => (new DisputeResource($dispute))->withEvidence($showEvidence),
        ]);
    }

    public function updateStatus(Request $request, Dispute $dispute): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'in:under_review'],
        ]);

        return $this->act($request, $dispute, fn (User $admin) => $this->disputes->markUnderReview($dispute, $admin), 'Dispute moved to under review.');
    }

    public function assign(Request $request, Dispute $dispute): JsonResponse
    {
        $validated = $request->validate([
            'admin_id' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
        ]);

        $assignee = isset($validated['admin_id']) ? User::find($validated['admin_id']) : null;

        return $this->act($request, $dispute, fn (User $admin) => $this->disputes->assign($dispute, $admin, $assignee),
            $assignee && $assignee->id !== $request->user()->id ? 'Case assigned.' : 'You have taken this case.');
    }

    public function unassign(Request $request, Dispute $dispute): JsonResponse
    {
        return $this->act($request, $dispute, fn (User $admin) => $this->disputes->unassign($dispute, $admin), 'Case returned to the queue.');
    }

    public function escalate(Request $request, Dispute $dispute): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'to_safety' => ['sometimes', 'boolean'],
        ]);

        $toSafety = (bool) ($validated['to_safety'] ?? false);

        return $this->act($request, $dispute,
            fn (User $admin) => $this->disputes->escalate($dispute, $admin, $validated['reason'], $toSafety),
            $toSafety ? 'Case sent to the Safety team.' : 'Case escalated.',
        );
    }

    public function deescalate(Request $request, Dispute $dispute): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        return $this->act($request, $dispute, fn (User $admin) => $this->disputes->deescalate($dispute, $admin, $validated['note']), 'Case sent back down a tier.');
    }

    public function resolve(ResolveDisputeFormRequest $request, Dispute $dispute): JsonResponse
    {
        $admin = $request->user();

        if ($denial = $this->denial($admin, $dispute)) {
            return response()->json(['message' => $denial], 403);
        }

        $refundAmount = $request->validated('refund_amount');

        try {
            $result = $this->disputes->resolve(
                $dispute,
                $admin,
                DisputeStatus::from($request->validated('status')),
                $request->validated('resolution_notes'),
                $refundAmount ? (float) $refundAmount : null,
                DisputeRefundMethod::from($request->validated('refund_method') ?? DisputeRefundMethod::Wallet->value),
                $request->boolean('clawback_driver_earnings', true),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $dispute = $result['dispute']->load(['reportedBy', 'resolvedBy', 'assignedAdmin', 'ride', 'pendingRefundRequest']);

        if ($result['refund_request']) {
            return response()->json([
                'message' => 'The refund needs a second approval. The case stays open until it is approved.',
                'dispute' => new DisputeResource($dispute),
                'refund_request' => new DisputeRefundRequestResource($result['refund_request']->load('requestedBy')),
            ], 202);
        }

        $message = 'Dispute resolved successfully.';
        if ($refundAmount) {
            $message = "Dispute resolved successfully. Refund of {$dispute->refund_currency} ".number_format((float) $refundAmount, 2).' issued.';
        }

        return response()->json([
            'message' => $message,
            'dispute' => new DisputeResource($dispute),
            'driver_clawback_kobo' => $result['clawback_kobo'],
        ]);
    }

    /**
     * Run a case action for an admin who may work the case.
     *
     * @param  callable(User): Dispute  $action
     */
    private function act(Request $request, Dispute $dispute, callable $action, string $message): JsonResponse
    {
        $admin = $request->user();

        if ($denial = $this->denial($admin, $dispute)) {
            return response()->json(['message' => $denial], 403);
        }

        try {
            $dispute = $action($admin);
        } catch (AccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $dispute->load(['reportedBy', 'resolvedBy', 'assignedAdmin', 'ride', 'pendingRefundRequest']);

        return response()->json([
            'message' => $message,
            'dispute' => new DisputeResource($dispute),
        ]);
    }

    /**
     * Why the admin cannot work the case, or null when they can.
     */
    private function denial(User $admin, Dispute $dispute): ?string
    {
        if (! $this->access->canView($admin, $dispute)) {
            return 'Safety cases are only visible to the Safety team.';
        }

        return $this->access->actDenial($admin, $dispute);
    }

    /**
     * Queue counts within what the admin can see.
     *
     * @return array<string, int>
     */
    private function summary(User $admin): array
    {
        $open = fn () => $this->access->scopeVisible(Dispute::query(), $admin)->open();

        return [
            'open' => $open()->count(),
            'unassigned' => $open()->whereNull('assigned_admin_id')->count(),
            'mine' => $open()->where('assigned_admin_id', $admin->id)->count(),
            'overdue' => $this->wherePastDue($open())->count(),
            'safety' => $open()->where('queue', 'safety')->count(),
            'tier_1' => $open()->where('queue', 'support')->where('escalation_tier', 1)->count(),
            'tier_2' => $open()->where('queue', 'support')->where('escalation_tier', 2)->count(),
            'tier_3' => $open()->where('escalation_tier', 3)->count(),
            'appeals' => $open()->whereNotNull('appealed_at')->count(),
            'pending_refunds' => DisputeRefundRequest::pending()
                ->whereHas('dispute', fn ($q) => $this->access->scopeVisible($q, $admin))
                ->count(),
        ];
    }

    private function wherePastDue(Builder $query): Builder
    {
        $now = now();

        return $query->where(function ($q) use ($now) {
            $q->where(fn ($q) => $q->whereNull('first_responded_at')->where('first_response_due_at', '<=', $now))
                ->orWhere('resolution_due_at', '<=', $now);
        });
    }
}
