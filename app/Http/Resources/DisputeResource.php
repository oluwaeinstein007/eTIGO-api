<?php

namespace App\Http\Resources;

use App\Enums\DisputeQueue;
use App\Http\Resources\Concerns\ResolvesUploadUrls;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\DisputeAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dispute, cut for the viewer. Admins get the routing, targets, appeal
 * and the actions they can take; evidence only where they may see it
 * (NF-12) and only when the controller asks for it. The reporter gets the
 * stage of their case and what they can do, never an admin's name.
 */
class DisputeResource extends JsonResource
{
    use ResolvesUploadUrls;

    private bool $withEvidence = false;

    /**
     * Include evidence files for an admin (single-case views). The
     * controller has already checked the admin may see them.
     */
    public function withEvidence(bool $show = true): static
    {
        $this->withEvidence = $show;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isAdmin = (bool) $viewer?->isAdmin();
        // The reporter always sees their own files; admins only in a
        // single-case view they may see evidence in.
        $showEvidence = $this->withEvidence || ! $isAdmin;

        $base = [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'ride_id' => $this->ride_id,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'evidence_photos' => $this->when($showEvidence && $this->evidence_photos, function () {
                return collect($this->evidence_photos)->map(
                    fn (string $path) => $this->resolveUploadUrl($path),
                )->all();
            }),
            'evidence_audio' => $this->when($showEvidence && $this->evidence_audio, function () {
                return $this->resolveUploadUrl($this->evidence_audio);
            }),
            'evidence' => $this->when($showEvidence && $this->relationLoaded('evidence'), fn () => DisputeEvidenceResource::collection($this->evidence)),
            'evidence_count' => $this->when(isset($this->evidence_count) || $this->relationLoaded('evidence'), fn () => $this->evidence_count ?? $this->evidence->count()),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'resolution_notes' => $this->resolution_notes,
            'refund_amount' => $this->refund_amount,
            'refund_currency' => $this->refund_currency,
            'refund_method' => $this->refund_method?->value,
            'reported_by' => new UserResource($this->whenLoaded('reportedBy')),
            'ride' => new RideResource($this->whenLoaded('ride')),
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        $settings = self::settings($request);

        return $isAdmin ? $base + $this->adminFields($viewer, $settings) : $base + $this->reporterFields($settings);
    }

    /**
     * Dispute settings, read once per request however many cases are listed.
     *
     * @return array<string, mixed>
     */
    private static function settings(Request $request): array
    {
        if (! $request->attributes->has('dispute_settings')) {
            $request->attributes->set('dispute_settings', AppSetting::disputes());
        }

        return $request->attributes->get('dispute_settings');
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function adminFields(User $viewer, array $settings): array
    {
        $access = app(DisputeAccess::class);
        $sla = $this->slaStatus();
        $canSeeEvidence = $access->canViewEvidence($viewer, $this->resource);

        return [
            'queue' => $this->queue->value,
            'queue_label' => $this->queue->label(),
            'escalation_tier' => $this->escalation_tier,
            'tier_label' => $this->tierLabel(),
            'decision_tier' => $this->decision_tier,
            'assigned_admin' => $this->whenLoaded('assignedAdmin', fn () => $this->assignedAdmin ? [
                'id' => $this->assignedAdmin->id,
                'first_name' => $this->assignedAdmin->first_name,
                'last_name' => $this->assignedAdmin->last_name,
                'admin_role' => $this->assignedAdmin->admin_role?->value,
            ] : null),
            'assigned_admin_id' => $this->assigned_admin_id,
            'assigned_at' => $this->assigned_at,
            'is_mine' => $this->assigned_admin_id === $viewer->id,
            'escalation' => $this->escalated_at ? [
                'escalated_at' => $this->escalated_at,
                'reason' => $this->escalation_reason,
                'source' => $this->escalation_source,
                'escalated_by_admin_id' => $this->escalated_by_admin_id,
            ] : null,
            'sla' => [
                'status' => $sla->value,
                'status_label' => $sla->label(),
                'next_due_at' => $this->nextDueAt(),
                'first_response_due_at' => $this->first_response_due_at,
                'first_responded_at' => $this->first_responded_at,
                'first_response_breached_at' => $this->first_response_breached_at,
                'resolution_due_at' => $this->resolution_due_at,
                'resolution_breached_at' => $this->resolution_breached_at,
            ],
            'appeal' => $this->appealed_at ? [
                'appealed_at' => $this->appealed_at,
                'reason' => $this->appeal_reason,
                'original_decision' => $this->original_decision,
                'excluded_reviewer_ids' => $this->excludedReviewerIds(),
            ] : null,
            'driver_clawback_amount' => $this->driver_clawback_amount,
            'linked_dispute_id' => $this->linked_dispute_id,
            'linked_dispute' => $this->whenLoaded('linkedDispute', fn () => $this->linkedDispute ? [
                'id' => $this->linkedDispute->id,
                'reference_number' => $this->linkedDispute->reference_number,
                'category' => $this->linkedDispute->category->value,
                'status' => $this->linkedDispute->status->value,
                'reported_by_user_id' => $this->linkedDispute->reported_by_user_id,
            ] : null),
            'sos_incident_id' => $this->sos_incident_id,
            'evidence_locked' => ! $canSeeEvidence,
            'pending_refund_request' => $this->whenLoaded('pendingRefundRequest', fn () => $this->pendingRefundRequest
                ? new DisputeRefundRequestResource($this->pendingRefundRequest->loadMissing('requestedBy'))
                : null),
            'refund_requests' => DisputeRefundRequestResource::collection($this->whenLoaded('refundRequests')),
            'resolved_by' => new UserResource($this->whenLoaded('resolvedBy')),
            'notes' => DisputeNoteResource::collection($this->whenLoaded('notes')),
            'available_actions' => $this->adminActions($access, $viewer),
            'refund_limit' => $access->refundLimit($viewer, $settings),
        ];
    }

    /**
     * @return list<string>
     */
    private function adminActions(DisputeAccess $access, User $viewer): array
    {
        if (! $this->isOpen() || ! $access->canAct($viewer, $this->resource)) {
            return [];
        }

        $actions = [];

        if ($this->assigned_admin_id !== $viewer->id) {
            $actions[] = 'take';
        }

        if ($access->canAssignOthers($viewer)) {
            $actions[] = 'assign';
        }

        if ($this->assigned_admin_id !== null) {
            $actions[] = 'unassign';
        }

        if ($this->status->value === 'open') {
            $actions[] = 'mark_under_review';
        }

        if ($this->escalation_tier < 3) {
            $actions[] = 'escalate';

            if ($this->queue === DisputeQueue::Support) {
                $actions[] = 'send_to_safety';
            }
        }

        if ($this->escalation_tier > 1 || $this->queue === DisputeQueue::Safety) {
            $actions[] = 'deescalate';
        }

        $actions[] = 'resolve';
        $actions[] = 'add_note';

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function reporterFields(array $settings): array
    {
        $blocker = $this->appealBlocker($settings);
        $max = $settings['max_evidence_files'];
        $count = $this->relationLoaded('evidence') ? $this->evidence->count() : null;

        return [
            'stage' => $this->status->isTerminal() ? 'decided' : ($this->escalation_tier > 1 || $this->queue === DisputeQueue::Safety ? 'senior_review' : 'review'),
            'stage_label' => $this->stageLabel(),
            'can_add_evidence' => $this->isOpen() && ($count === null || $count < $max),
            'evidence_slots_left' => $count === null ? null : max(0, $max - $count),
            'appeal' => [
                'can_appeal' => $blocker === null,
                'reason_unavailable' => $blocker,
                'deadline' => $this->appealDeadline($settings),
                'appealed_at' => $this->appealed_at,
            ],
        ];
    }
}
