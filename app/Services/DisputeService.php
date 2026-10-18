<?php

namespace App\Services;

use App\Enums\AdminRole;
use App\Enums\DisputeCategory;
use App\Enums\DisputeEvidenceType;
use App\Enums\DisputeQueue;
use App\Enums\DisputeRefundMethod;
use App\Enums\DisputeRefundRequestStatus;
use App\Enums\DisputeStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Exceptions\AccessDeniedException;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\DisputeNote;
use App\Models\DisputeRefundRequest;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use App\Notifications\DisputeAlertNotification;
use App\Notifications\DisputeResolvedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 2 advanced disputes (OQ-21).
 *
 * A new dispute starts at tier 1 of the Support queue, or with the Safety
 * team for a safety concern. A reviewer takes it (the first response),
 * works it, and resolves or dismisses it. Cases move up a tier when a
 * reviewer escalates them or tier 1 misses a target, and back down with
 * instructions. A refund above the reviewer's limit, or back to the card,
 * waits for a second admin. The reporter can appeal once, within the
 * window, to the tier above whoever decided; that person cannot decide
 * again. The passenger's and the driver's disputes on one trip are linked
 * and follow the same routing and owner.
 *
 * Every change runs under a row lock and is audit-logged; the reporter's
 * notifications never name an admin.
 */
class DisputeService
{
    public function __construct(
        private DisputeAccess $access,
        private WalletRefundService $refunds,
        private PaymentService $payments,
        private NotificationService $notifications,
    ) {}

    /**
     * File a dispute: route it, set its targets, link it to the other
     * side's dispute on the trip and to any SOS incident.
     *
     * @param  list<array{path: string, type: DisputeEvidenceType, mime: ?string, size: ?int, name: ?string}>  $evidence
     */
    public function file(Ride $ride, User $reporter, DisputeCategory $category, string $description, array $evidence = []): Dispute
    {
        return DB::transaction(function () use ($ride, $reporter, $category, $description, $evidence) {
            // Serialise filings on the trip so the passenger's and the
            // driver's disputes always find each other to link.
            Ride::whereKey($ride->id)->lockForUpdate()->first();

            $isSafety = $category === DisputeCategory::SafetyConcern;
            $queue = $isSafety ? DisputeQueue::Safety : DisputeQueue::Support;
            $tier = $isSafety ? 2 : 1;

            $other = Dispute::where('ride_id', $ride->id)
                ->where('reported_by_user_id', '!=', $reporter->id)
                ->lockForUpdate()
                ->first();

            // Joined with the other side's open case: the higher routing wins.
            if ($other?->isOpen() && $this->routingRank($other->queue, $other->escalation_tier) > $this->routingRank($queue, $tier)) {
                $queue = $other->queue;
                $tier = $other->escalation_tier;
            }

            $now = now();
            $photos = array_values(array_map(fn ($e) => $e['path'], array_filter($evidence, fn ($e) => $e['type'] === DisputeEvidenceType::Photo)));
            $audio = collect($evidence)->firstWhere('type', DisputeEvidenceType::Audio)['path'] ?? null;

            $dispute = $ride->disputes()->create([
                'reported_by_user_id' => $reporter->id,
                'category' => $category,
                'description' => $description,
                // Kept for apps that read the old fields.
                'evidence_photos' => $photos ?: null,
                'evidence_audio' => $audio,
                'status' => DisputeStatus::Open,
                'queue' => $queue,
                'escalation_tier' => $tier,
                'linked_dispute_id' => $other?->id,
                'sos_incident_id' => $isSafety ? $this->latestSosIncidentId($ride) : null,
                ...$this->targets($queue, $tier, $now),
            ]);

            $this->storeEvidence($dispute, $reporter, $evidence);

            AuditLog::record($ride, 'dispute_filed', $reporter, null, [
                'dispute_id' => $dispute->id,
                'category' => $category->value,
                'queue' => $queue->value,
                'escalation_tier' => $tier,
            ]);

            if ($other !== null) {
                $other->update(['linked_dispute_id' => $dispute->id]);

                if ($other->isOpen()) {
                    // Same owner for both sides, when they can work it; then
                    // the other side follows this one's routing.
                    if ($other->assigned_admin_id && ($owner = User::find($other->assigned_admin_id)) && $this->access->canBeAssigned($owner, $dispute)) {
                        $dispute->update([
                            'assigned_admin_id' => $owner->id,
                            'assigned_at' => $now,
                        ]);
                    }

                    $this->syncLinked($dispute, $reporter);
                }
            }

            if ($dispute->queue === DisputeQueue::Safety) {
                $this->alertSafetyTeam($dispute, 'A safety concern was reported on a trip. Please respond within the target.');
            }

            return $dispute->refresh();
        });
    }

    /**
     * Give the case to an admin (by default, the actor takes it). Taking a
     * case is its first response and moves it to under review.
     */
    public function assign(Dispute $dispute, User $actor, ?User $assignee = null): Dispute
    {
        $assignee ??= $actor;

        return DB::transaction(function () use ($dispute, $actor, $assignee) {
            $locked = $this->lockOpen($dispute);

            if ($assignee->id !== $actor->id && ! $this->access->canAssignOthers($actor)) {
                throw new \DomainException('You can only take a case yourself.');
            }

            if (! $this->access->canBeAssigned($assignee, $locked)) {
                throw new \DomainException($assignee->id === $actor->id
                    ? ($this->access->actDenial($actor, $locked) ?? 'You cannot take this case.')
                    : 'That admin cannot work this case.');
            }

            $old = $locked->assigned_admin_id;
            $firstResponse = $locked->first_responded_at === null;

            $locked->update([
                'assigned_admin_id' => $assignee->id,
                'assigned_at' => now(),
                'status' => DisputeStatus::UnderReview,
                'first_responded_at' => $locked->first_responded_at ?? now(),
            ]);

            AuditLog::record($locked, 'dispute_assigned', $actor, ['assigned_admin_id' => $old], [
                'assigned_admin_id' => $assignee->id,
            ]);

            $this->syncLinked($locked, $actor);

            if ($firstResponse) {
                $this->notifyReporter($locked, 'We are looking into your report',
                    "A member of our team is now reviewing report {$locked->reference_number}.");
            }

            return $locked;
        });
    }

    /**
     * Put the case back in its tier's shared queue.
     */
    public function unassign(Dispute $dispute, User $actor, string $event = 'dispute_unassigned', ?string $reason = null): Dispute
    {
        return DB::transaction(function () use ($dispute, $actor, $event, $reason) {
            $locked = $this->lockOpen($dispute);
            $old = $locked->assigned_admin_id;

            $locked->update(['assigned_admin_id' => null, 'assigned_at' => null]);

            AuditLog::record($locked, $event, $actor, ['assigned_admin_id' => $old], array_filter([
                'assigned_admin_id' => null,
                'reason' => $reason,
            ]));

            $this->syncLinked($locked, $actor);

            return $locked;
        });
    }

    /**
     * The old "acknowledge" step (PATCH status → under_review): counts as
     * the first response.
     */
    public function markUnderReview(Dispute $dispute, User $actor): Dispute
    {
        return DB::transaction(function () use ($dispute, $actor) {
            $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DisputeStatus::Open) {
                throw new \DomainException('Only open disputes can be moved to under review.');
            }

            $locked->update([
                'status' => DisputeStatus::UnderReview,
                'first_responded_at' => $locked->first_responded_at ?? now(),
            ]);

            AuditLog::record($locked, 'dispute_status_changed', $actor, [
                'status' => DisputeStatus::Open->value,
            ], [
                'status' => DisputeStatus::UnderReview->value,
            ]);

            $this->notifyReporter($locked, 'We are looking into your report',
                "A member of our team is now reviewing report {$locked->reference_number}.");

            return $locked;
        });
    }

    /**
     * Move the case up one tier, or to the Safety team. The new tier's
     * targets start now and the case goes to its shared queue.
     */
    public function escalate(Dispute $dispute, ?User $actor, string $reason, bool $toSafety = false, string $source = Dispute::SOURCE_ADMIN): Dispute
    {
        return DB::transaction(function () use ($dispute, $actor, $reason, $toSafety, $source) {
            $locked = $this->lockOpen($dispute, 'A closed dispute cannot be escalated. It can only reopen through an appeal.');

            if ($toSafety) {
                if ($locked->queue === DisputeQueue::Safety) {
                    throw new \DomainException('This case is already with the Safety team.');
                }

                if ($locked->escalation_tier >= 3) {
                    throw new \DomainException('This case is at tier 3; the super admin handling it covers safety too.');
                }

                $queue = DisputeQueue::Safety;
                $tier = 2;
            } else {
                if ($locked->escalation_tier >= 3) {
                    throw new \DomainException('This case is already at tier 3, the highest tier.');
                }

                $queue = $locked->queue;
                $tier = $locked->escalation_tier + 1;
            }

            $old = $this->routingSnapshot($locked);

            $locked->update([
                'queue' => $queue,
                'escalation_tier' => $tier,
                'assigned_admin_id' => null,
                'assigned_at' => null,
                'escalated_by_admin_id' => $actor?->id,
                'escalated_at' => now(),
                'escalation_reason' => $reason,
                'escalation_source' => $source,
                'sos_incident_id' => $locked->sos_incident_id ?? ($toSafety ? $this->latestSosIncidentId($locked->ride) : null),
                ...$this->targets($queue, $tier, now()),
            ]);

            AuditLog::record($locked, $source === Dispute::SOURCE_SYSTEM ? 'dispute_auto_escalated' : 'dispute_escalated', $actor, $old, [
                ...$this->routingSnapshot($locked),
                'reason' => $reason,
            ]);

            $this->syncLinked($locked, $actor);

            if ($toSafety) {
                $this->alertSafetyTeam($locked, "A case was sent to the Safety team: {$reason}");
                $this->notifyReporter($locked, 'Your report is with our safety team',
                    "Report {$locked->reference_number} is now being handled by our safety team.");
            } else {
                $this->notifyReporter($locked, 'Your case is with our senior team',
                    "Report {$locked->reference_number} has been passed to our senior team for a closer look.");
            }

            return $locked;
        });
    }

    /**
     * Send the case back down a tier with instructions (a note is kept on
     * the case). From the Safety team it returns to Support tier 1.
     */
    public function deescalate(Dispute $dispute, User $actor, string $note): Dispute
    {
        return DB::transaction(function () use ($dispute, $actor, $note) {
            $locked = $this->lockOpen($dispute);

            [$queue, $tier] = match (true) {
                $locked->escalation_tier >= 3 => [$locked->queue, 2],
                $locked->queue === DisputeQueue::Safety => [DisputeQueue::Support, 1],
                $locked->escalation_tier === 2 => [DisputeQueue::Support, 1],
                default => throw new \DomainException('This case is already at tier 1.'),
            };

            // An appeal stays above the tier that made the decision.
            $decidedAt = $locked->original_decision['decision_tier'] ?? null;

            if ($locked->isAppeal() && $decidedAt !== null && $tier <= $decidedAt) {
                throw new \DomainException('An appeal must stay above the tier that made the original decision.');
            }

            $old = $this->routingSnapshot($locked);

            $locked->update([
                'queue' => $queue,
                'escalation_tier' => $tier,
                'assigned_admin_id' => null,
                'assigned_at' => null,
                ...$this->targets($queue, $tier, now()),
            ]);

            DisputeNote::create([
                'dispute_id' => $locked->id,
                'admin_id' => $actor->id,
                'content' => "Sent back to {$locked->tierLabel()}: {$note}",
            ]);

            AuditLog::record($locked, 'dispute_deescalated', $actor, $old, [
                ...$this->routingSnapshot($locked),
                'note' => $note,
            ]);

            $this->syncLinked($locked, $actor);

            return $locked;
        });
    }

    /**
     * Decide the case. A refund within the actor's limit is paid at once to
     * the wallet; above it, or to the card, it becomes a refund request and
     * the case stays open until a second admin approves it.
     *
     * @return array{dispute: Dispute, refund_request: ?DisputeRefundRequest, clawback_kobo: int}
     */
    public function resolve(
        Dispute $dispute,
        User $actor,
        DisputeStatus $status,
        string $notes,
        ?float $refundAmount = null,
        DisputeRefundMethod $method = DisputeRefundMethod::Wallet,
        bool $clawback = true,
    ): array {
        return DB::transaction(function () use ($dispute, $actor, $status, $notes, $refundAmount, $method, $clawback) {
            $locked = $this->lockOpen($dispute, 'This dispute has already been resolved or dismissed.');

            if ($locked->pendingRefundRequest()->exists()) {
                throw new \DomainException('A refund on this case is waiting for approval. Approve, reject or withdraw it first.');
            }

            if (! $refundAmount) {
                return ['dispute' => $this->finalise($locked, $actor, $status, $notes), 'refund_request' => null, 'clawback_kobo' => 0];
            }

            $this->assertRefundable($locked, $refundAmount, $method);

            $total = $refundAmount + (float) ($locked->refund_amount ?? 0);

            if ($method === DisputeRefundMethod::Card || ! $this->access->withinRefundLimit($actor, $total)) {
                $request = $locked->refundRequests()->create([
                    'requested_by_admin_id' => $actor->id,
                    'amount' => $refundAmount,
                    'currency' => $locked->ride->fare_currency ?? 'NGN',
                    'method' => $method,
                    'clawback_driver_earnings' => $clawback,
                    'resolution_notes' => $notes,
                    'status' => DisputeRefundRequestStatus::Pending,
                ]);

                $locked->update([
                    'status' => DisputeStatus::UnderReview,
                    'first_responded_at' => $locked->first_responded_at ?? now(),
                ]);

                AuditLog::record($locked, 'dispute_refund_requested', $actor, null, [
                    'refund_request_id' => $request->id,
                    'amount' => $refundAmount,
                    'method' => $method->value,
                    'limit' => $this->access->refundLimit($actor),
                ]);

                return ['dispute' => $locked, 'refund_request' => $request, 'clawback_kobo' => 0];
            }

            $clawbackKobo = $this->payRefund($locked, $actor, $refundAmount, $method, $clawback);

            return ['dispute' => $this->finalise($locked, $actor, $status, $notes), 'refund_request' => null, 'clawback_kobo' => $clawbackKobo];
        });
    }

    /**
     * Approve a refund request: pay it and resolve the case with the
     * requester's decision.
     *
     * @return array{dispute: Dispute, refund_request: DisputeRefundRequest, clawback_kobo: int}
     */
    public function approveRefund(DisputeRefundRequest $request, User $approver, ?string $notes = null): array
    {
        if ($request->method === DisputeRefundMethod::Card) {
            return $this->approveCardRefund($request, $approver, $notes);
        }

        return DB::transaction(function () use ($request, $approver, $notes) {
            [$locked, $dispute] = $this->lockForApproval($request, $approver);

            $this->assertRefundable($dispute, (float) $locked->amount, $locked->method);
            $clawbackKobo = $this->payRefund($dispute, $approver, (float) $locked->amount, $locked->method, $locked->clawback_driver_earnings);

            return $this->completeApproval($locked, $dispute, $approver, $notes, $clawbackKobo);
        });
    }

    /**
     * A card refund goes out through the payment provider, which a database
     * rollback cannot undo. So the request is marked as sent before the
     * provider is called, and the case is closed in a second transaction.
     * If that second step fails after the provider refunded, the mark stays
     * and the request cannot be approved again (no second refund); Finance
     * checks the payment.
     *
     * @return array{dispute: Dispute, refund_request: DisputeRefundRequest, clawback_kobo: int}
     */
    private function approveCardRefund(DisputeRefundRequest $request, User $approver, ?string $notes): array
    {
        $sent = DB::transaction(function () use ($request, $approver) {
            [$locked, $dispute] = $this->lockForApproval($request, $approver);

            if ($locked->refund_sent_at !== null) {
                throw new \DomainException('This card refund was already sent to the payment provider. Finance must check the payment before anything else is done with this request.');
            }

            $this->assertRefundable($dispute, (float) $locked->amount, $locked->method);
            $locked->update(['refund_sent_at' => now()]);

            return $locked;
        });

        $ride = Dispute::findOrFail($sent->dispute_id)->ride;

        try {
            $this->payments->refundPayment($ride->payment, (float) $sent->amount);
        } catch (\Throwable $e) {
            // The provider refused or the call failed: nothing was refunded,
            // so the request can be approved again.
            DisputeRefundRequest::whereKey($sent->id)->update(['refund_sent_at' => null]);
            Log::warning('Dispute card refund failed', ['refund_request_id' => $sent->id, 'error' => $e->getMessage()]);

            throw new \DomainException('The payment provider did not accept the card refund: '.$e->getMessage());
        }

        return DB::transaction(function () use ($sent, $approver, $notes) {
            $locked = DisputeRefundRequest::whereKey($sent->id)->lockForUpdate()->firstOrFail();
            $dispute = Dispute::whereKey($locked->dispute_id)->lockForUpdate()->firstOrFail();

            $this->recordRefund($dispute, (float) $locked->amount, DisputeRefundMethod::Card, 0);

            return $this->completeApproval($locked, $dispute, $approver, $notes, 0);
        });
    }

    /**
     * Lock a pending request and its open case and check the approver.
     *
     * @return array{0: DisputeRefundRequest, 1: Dispute}
     */
    private function lockForApproval(DisputeRefundRequest $request, User $approver): array
    {
        $locked = DisputeRefundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            throw new \DomainException('This refund request has already been '.$locked->status->label().'.');
        }

        $dispute = $this->lockOpen($locked->dispute, 'This dispute is already closed.');
        $locked->setRelation('dispute', $dispute);

        if ($denial = $this->access->approvalDenial($approver, $locked)) {
            throw new AccessDeniedException($denial);
        }

        return [$locked, $dispute];
    }

    /**
     * Mark the request approved and resolve the case with the requester's
     * decision.
     *
     * @return array{dispute: Dispute, refund_request: DisputeRefundRequest, clawback_kobo: int}
     */
    private function completeApproval(DisputeRefundRequest $locked, Dispute $dispute, User $approver, ?string $notes, int $clawbackKobo): array
    {
        $locked->update([
            'status' => DisputeRefundRequestStatus::Approved,
            'reviewed_by_admin_id' => $approver->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        AuditLog::record($dispute, 'dispute_refund_approved', $approver, null, [
            'refund_request_id' => $locked->id,
            'amount' => (float) $locked->amount,
            'method' => $locked->method->value,
        ]);

        $decider = $locked->requested_by_admin_id ? User::find($locked->requested_by_admin_id) : null;
        $dispute = $this->finalise($dispute, $decider ?? $approver, DisputeStatus::Resolved, $locked->resolution_notes);

        return ['dispute' => $dispute, 'refund_request' => $locked, 'clawback_kobo' => $clawbackKobo];
    }

    /**
     * Turn a refund request down (or, by the requester, withdraw it). The
     * case stays open for a new decision.
     */
    public function rejectRefund(DisputeRefundRequest $request, User $actor, string $notes): DisputeRefundRequest
    {
        return DB::transaction(function () use ($request, $actor, $notes) {
            $locked = DisputeRefundRequest::with('dispute')->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new \DomainException('This refund request has already been '.$locked->status->label().'.');
            }

            $withdrawn = $locked->requested_by_admin_id === $actor->id;

            if (! $withdrawn && ($denial = $this->access->approvalDenial($actor, $locked))) {
                throw new AccessDeniedException($denial);
            }

            $locked->update([
                'status' => $withdrawn ? DisputeRefundRequestStatus::Cancelled : DisputeRefundRequestStatus::Rejected,
                'reviewed_by_admin_id' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            AuditLog::record($locked->dispute, $withdrawn ? 'dispute_refund_withdrawn' : 'dispute_refund_rejected', $actor, null, [
                'refund_request_id' => $locked->id,
                'notes' => $notes,
            ]);

            return $locked;
        });
    }

    /**
     * The reporter appeals the decision: the case reopens one tier above
     * whoever decided it, for a different reviewer.
     */
    public function appeal(Dispute $dispute, User $reporter, string $reason): Dispute
    {
        return DB::transaction(function () use ($dispute, $reporter, $reason) {
            $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($locked->reported_by_user_id !== $reporter->id) {
                throw new AccessDeniedException('Only the person who reported this can appeal it.');
            }

            if ($blocker = $locked->appealBlocker()) {
                throw new \DomainException($blocker);
            }

            $tier = min(3, ($locked->decision_tier ?? $locked->escalation_tier) + 1);
            $queue = $locked->queue;

            $approvedBy = $locked->refundRequests()
                ->where('status', DisputeRefundRequestStatus::Approved)
                ->value('reviewed_by_admin_id');

            $original = [
                'status' => $locked->status->value,
                'resolution_notes' => $locked->resolution_notes,
                'resolved_by_admin_id' => $locked->resolved_by_admin_id,
                'resolved_at' => $locked->resolved_at?->toIso8601String(),
                'decision_tier' => $locked->decision_tier,
                'refund_amount' => $locked->refund_amount,
                'refund_approved_by_admin_id' => $approvedBy,
            ];

            $locked->update([
                'status' => DisputeStatus::Open,
                'queue' => $queue,
                'escalation_tier' => $tier,
                'original_decision' => $original,
                'appealed_at' => now(),
                'appeal_reason' => $reason,
                'appeal_of_resolver_id' => $locked->resolved_by_admin_id,
                'resolution_notes' => null,
                'resolved_by_admin_id' => null,
                'resolved_at' => null,
                'decision_tier' => null,
                'assigned_admin_id' => null,
                'assigned_at' => null,
                'escalated_by_admin_id' => null,
                'escalated_at' => now(),
                'escalation_reason' => 'Appeal by the reporter.',
                'escalation_source' => Dispute::SOURCE_APPEAL,
                ...$this->targets($queue, $tier, now()),
            ]);

            AuditLog::record($locked, 'dispute_appealed', $reporter, $original, [
                ...$this->routingSnapshot($locked),
                'reason' => $reason,
            ]);

            if ($locked->queue === DisputeQueue::Safety && $tier < 3) {
                $this->alertSafetyTeam($locked, 'A decision on a safety case was appealed.');
            }

            $this->notifyReporter($locked, 'Your appeal is with our senior team',
                "We have reopened report {$locked->reference_number}. A different, more senior reviewer will look at it.");

            return $locked;
        });
    }

    /**
     * Add evidence files to an open case. Callers check who may upload.
     *
     * @param  list<array{path: string, type: DisputeEvidenceType, mime: ?string, size: ?int, name: ?string, caption?: ?string}>  $evidence
     */
    public function addEvidence(Dispute $dispute, User $uploader, array $evidence): Dispute
    {
        return DB::transaction(function () use ($dispute, $uploader, $evidence) {
            $locked = $this->lockOpen($dispute, 'Evidence can only be added while the case is open.');
            $max = AppSetting::disputes()['max_evidence_files'];

            if ($locked->evidence()->count() + count($evidence) > $max) {
                throw new \DomainException("A case can hold at most {$max} evidence files.");
            }

            $this->storeEvidence($locked, $uploader, $evidence);

            AuditLog::record($locked, 'dispute_evidence_added', $uploader, null, [
                'files' => count($evidence),
            ]);

            return $locked;
        });
    }

    /**
     * Return an admin's open cases to their tiers' shared queues: when they
     * are deactivated or deleted, or (with $onlyIfUnable) when a role
     * change means they can no longer work them.
     */
    public function releaseAssignments(User $admin, ?User $actor, string $reason, bool $onlyIfUnable = false): int
    {
        $released = 0;

        Dispute::open()->where('assigned_admin_id', $admin->id)->pluck('id')->each(function (string $id) use ($admin, $actor, $reason, $onlyIfUnable, &$released) {
            DB::transaction(function () use ($id, $admin, $actor, $reason, $onlyIfUnable, &$released) {
                $locked = Dispute::whereKey($id)->lockForUpdate()->first();

                if (! $locked?->isOpen() || $locked->assigned_admin_id !== $admin->id) {
                    return;
                }

                if ($onlyIfUnable && $this->access->canAct($admin, $locked)) {
                    return;
                }

                $locked->update(['assigned_admin_id' => null, 'assigned_at' => null]);

                AuditLog::record($locked, 'dispute_returned_to_queue', $actor, ['assigned_admin_id' => $admin->id], [
                    'assigned_admin_id' => null,
                    'reason' => $reason,
                ]);

                $released++;
            });
        });

        return $released;
    }

    /**
     * The 15-minute sweep: flag cases that missed a target, move tier 1
     * misses to tier 2, and alert super admins about Safety and tier 2
     * misses. Each miss is acted on once.
     *
     * @return array{escalated: int, alerted: int, flagged: int}
     */
    public function processTargets(): array
    {
        $counts = ['escalated' => 0, 'alerted' => 0, 'flagged' => 0];
        $now = now();

        Dispute::open()
            ->where(function ($q) use ($now) {
                $q->where(fn ($q) => $q->whereNull('first_responded_at')
                    ->whereNull('first_response_breached_at')
                    ->where('first_response_due_at', '<=', $now))
                    ->orWhere(fn ($q) => $q->whereNull('resolution_breached_at')
                        ->where('resolution_due_at', '<=', $now));
            })
            ->orderBy('resolution_due_at')
            ->pluck('id')
            ->each(function (string $id) use (&$counts) {
                try {
                    $result = $this->handleMissedTarget($id);

                    if ($result !== null) {
                        $counts[$result]++;
                    }
                } catch (\Throwable $e) {
                    Log::error('Dispute target sweep failed for a case', ['dispute_id' => $id, 'error' => $e->getMessage()]);
                }
            });

        return $counts;
    }

    private function handleMissedTarget(string $id): ?string
    {
        return DB::transaction(function () use ($id) {
            $dispute = Dispute::whereKey($id)->lockForUpdate()->first();

            if (! $dispute?->isOpen()) {
                return null;
            }

            $now = now();
            $missed = null;

            if ($dispute->first_responded_at === null && $dispute->first_response_breached_at === null
                && $dispute->first_response_due_at?->lte($now)) {
                $dispute->first_response_breached_at = $now;
                $missed = 'first response';
            }

            if ($dispute->resolution_breached_at === null && $dispute->resolution_due_at?->lte($now)) {
                $dispute->resolution_breached_at = $now;
                $missed = 'resolution';
            }

            if ($missed === null) {
                return null;
            }

            $dispute->save();

            AuditLog::record($dispute, 'dispute_target_missed', null, null, [
                'target' => $missed,
                'queue' => $dispute->queue->value,
                'escalation_tier' => $dispute->escalation_tier,
            ]);

            $isTierOne = $dispute->queue === DisputeQueue::Support && $dispute->escalation_tier === 1;

            if ($isTierOne && AppSetting::disputes()['auto_escalate_tier_1']) {
                $this->escalate($dispute, null, "Tier 1 missed its {$missed} target.", false, Dispute::SOURCE_SYSTEM);

                return 'escalated';
            }

            if (! $isTierOne) {
                $this->alertSuperAdmins($dispute, "{$dispute->tierLabel()} missed the {$missed} target on this case.", $missed === 'resolution'
                    ? DisputeAlertNotification::RESOLUTION_MISSED
                    : DisputeAlertNotification::FIRST_RESPONSE_MISSED);

                return 'alerted';
            }

            return 'flagged';
        });
    }

    /**
     * Pay a dispute refund and record it on the case. Returns the driver
     * clawback in kobo. Card refunds outside an approval go through
     * approveCardRefund instead.
     */
    private function payRefund(Dispute $dispute, User $admin, float $amount, DisputeRefundMethod $method, bool $clawback): int
    {
        $ride = $dispute->ride;
        $clawbackKobo = 0;

        if ($method === DisputeRefundMethod::Card) {
            $this->payments->refundPayment($ride->payment, $amount);
        } else {
            $result = $this->refunds->refundRide(
                $ride,
                (int) round($amount * 100),
                "Refund for dispute {$dispute->reference_number}",
                $admin,
                $clawback,
                $dispute->id,
            );
            $clawbackKobo = $result['clawback_kobo'];
        }

        $this->recordRefund($dispute, $amount, $method, $clawbackKobo);

        return $clawbackKobo;
    }

    /**
     * Add a refund that has been paid to the case's running totals.
     */
    private function recordRefund(Dispute $dispute, float $amount, DisputeRefundMethod $method, int $clawbackKobo): void
    {
        $ride = $dispute->ride;

        $dispute->update([
            'refund_amount' => round((float) ($dispute->refund_amount ?? 0) + $amount, 2),
            'refund_currency' => $ride->payment?->currency ?? $ride->fare_currency ?? 'NGN',
            'refund_method' => $method,
            'driver_clawback_amount' => $clawbackKobo > 0
                ? round((float) ($dispute->driver_clawback_amount ?? 0) + $clawbackKobo / 100, 2)
                : $dispute->driver_clawback_amount,
        ]);
    }

    private function assertRefundable(Dispute $dispute, float $amount, DisputeRefundMethod $method): void
    {
        $ride = $dispute->ride;

        if ($ride->status !== RideStatus::Completed) {
            throw new \DomainException('Only completed rides can be refunded.');
        }

        if ($method === DisputeRefundMethod::Card) {
            $payment = $ride->payment;

            if (! $payment || $payment->method !== PaymentMethod::Card || ! $payment->gateway_transaction_id) {
                throw new \DomainException('A card refund needs a card payment taken through the gateway.');
            }

        }

        // One refundable balance for both methods, so a card refund after a
        // wallet refund (or the other way round) never refunds the fare twice.
        $remaining = $this->refunds->remainingRefundableKobo($ride);

        if ((int) round($amount * 100) > $remaining) {
            throw new \DomainException('Refund exceeds remaining refundable amount of ₦'.number_format($remaining / 100, 2).'.');
        }
    }

    private function finalise(Dispute $dispute, User $actor, DisputeStatus $status, string $notes): Dispute
    {
        $oldStatus = $dispute->status;

        $dispute->update([
            'status' => $status,
            'resolution_notes' => $notes,
            'resolved_by_admin_id' => $actor->id,
            'resolved_at' => now(),
            'decision_tier' => max($dispute->escalation_tier, $this->access->roleTier($actor) ?? 1),
            'first_responded_at' => $dispute->first_responded_at ?? now(),
        ]);

        AuditLog::record($dispute, 'dispute_resolved', $actor, [
            'status' => $oldStatus->value,
        ], array_filter([
            'status' => $status->value,
            'resolution_notes' => $notes,
            'refund_amount' => $dispute->refund_amount,
            'refund_method' => $dispute->refund_method?->value,
            'appeal' => $dispute->isAppeal() ?: null,
        ], fn ($v) => $v !== null));

        DB::afterCommit(function () use ($dispute) {
            rescue(fn () => $dispute->reportedBy?->notify(new DisputeResolvedNotification($dispute)));
        });

        $this->notifyReporter($dispute, 'A decision has been made on your report',
            "Report {$dispute->reference_number}: {$status->label()}. Open the app for details.");

        return $dispute;
    }

    /**
     * Lock the case and fail if it is closed.
     */
    private function lockOpen(Dispute $dispute, string $closedMessage = 'This dispute is closed.'): Dispute
    {
        $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw new \DomainException($closedMessage);
        }

        return $locked;
    }

    /**
     * Bring the other side's open dispute on the trip to the same routing
     * and owner (OQ-21: one owner for both).
     */
    private function syncLinked(Dispute $dispute, ?User $actor): void
    {
        if ($dispute->linked_dispute_id === null) {
            return;
        }

        $linked = Dispute::whereKey($dispute->linked_dispute_id)->lockForUpdate()->first();

        if (! $linked?->isOpen()) {
            return;
        }

        $changes = [];

        // An appeal never follows the other case down to (or below) the tier
        // that made its original decision; only the owner is shared then.
        $floor = $linked->isAppeal() ? ($linked->original_decision['decision_tier'] ?? null) : null;
        $belowFloor = $floor !== null && $dispute->escalation_tier <= $floor;

        if (! $belowFloor && ($linked->queue !== $dispute->queue || $linked->escalation_tier !== $dispute->escalation_tier)) {
            $changes = [
                'queue' => $dispute->queue,
                'escalation_tier' => $dispute->escalation_tier,
                ...$this->targets($dispute->queue, $dispute->escalation_tier, now()),
            ];
        }

        $old = $this->routingSnapshot($linked);
        $owner = $dispute->assigned_admin_id ? User::find($dispute->assigned_admin_id) : null;
        $linked->fill($changes);

        if ($owner === null || $this->access->canBeAssigned($owner, $linked)) {
            if ($linked->assigned_admin_id !== $dispute->assigned_admin_id) {
                $changes['assigned_admin_id'] = $dispute->assigned_admin_id;
                $changes['assigned_at'] = $dispute->assigned_admin_id ? now() : null;
            }
        }

        if ($changes === []) {
            return;
        }

        $linked->update($changes);

        AuditLog::record($linked, 'dispute_linked_synced', $actor, $old, [
            ...$this->routingSnapshot($linked),
            'from_dispute_id' => $dispute->id,
        ]);
    }

    /**
     * Due dates for a case entering a queue and tier at $from. Zero in a
     * setting means no target.
     *
     * @return array<string, mixed>
     */
    private function targets(DisputeQueue $queue, int $tier, \DateTimeInterface $from): array
    {
        $s = AppSetting::disputes();
        $from = Carbon::instance($from);

        [$firstMinutes, $resolutionHours] = match (true) {
            $tier >= 3 => [0, 0],
            $queue === DisputeQueue::Safety => [$s['safety_first_response_minutes'], $s['safety_resolution_hours']],
            $tier === 2 => [$s['tier_2_first_response_hours'] * 60, $s['tier_2_resolution_hours']],
            default => [$s['tier_1_first_response_hours'] * 60, $s['tier_1_resolution_hours']],
        };

        return [
            'sla_started_at' => $from,
            'first_response_due_at' => $firstMinutes > 0 ? $from->copy()->addMinutes($firstMinutes) : null,
            'resolution_due_at' => $resolutionHours > 0 ? $from->copy()->addHours($resolutionHours) : null,
            'first_responded_at' => null,
            'first_response_breached_at' => null,
            'resolution_breached_at' => null,
        ];
    }

    private function routingRank(DisputeQueue $queue, int $tier): int
    {
        return $tier * 2 + ($queue === DisputeQueue::Safety ? 1 : 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function routingSnapshot(Dispute $dispute): array
    {
        return [
            'queue' => $dispute->queue->value,
            'escalation_tier' => $dispute->escalation_tier,
            'assigned_admin_id' => $dispute->assigned_admin_id,
        ];
    }

    private function latestSosIncidentId(Ride $ride): ?string
    {
        return SosIncident::where('ride_id', $ride->id)->latest()->value('id');
    }

    /**
     * @param  list<array{path: string, type: DisputeEvidenceType, mime: ?string, size: ?int, name: ?string, caption?: ?string}>  $evidence
     */
    private function storeEvidence(Dispute $dispute, User $uploader, array $evidence): void
    {
        foreach ($evidence as $file) {
            $dispute->evidence()->create([
                'uploaded_by_user_id' => $uploader->id,
                'type' => $file['type'],
                'file_path' => $file['path'],
                'file_type' => $file['mime'],
                'file_size_bytes' => $file['size'],
                'original_name' => $file['name'],
                'caption' => $file['caption'] ?? null,
                'uploaded_at' => now(),
            ]);
        }
    }

    /**
     * Push and in-app update to the reporter, after commit, never failing
     * the request and never naming an admin.
     */
    private function notifyReporter(Dispute $dispute, string $title, string $body): void
    {
        DB::afterCommit(function () use ($dispute, $title, $body) {
            try {
                $this->notifications->send($dispute->reported_by_user_id, NotificationType::DisputeUpdate, $title, $body, [
                    'dispute_id' => (string) $dispute->id,
                    'reference_number' => (string) $dispute->reference_number,
                    'ride_id' => (string) $dispute->ride_id,
                    'status' => $dispute->status->value,
                    'stage' => $dispute->stageLabel(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Dispute notification failed', ['dispute_id' => $dispute->id, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Email the Safety team, or the super admins when there is no active
     * safety operator.
     */
    private function alertSafetyTeam(Dispute $dispute, string $detail): void
    {
        $this->alertAdmins($dispute, [AdminRole::SafetyOperator], DisputeAlertNotification::NEW_SAFETY_CASE, $detail, [AdminRole::SuperAdmin]);
    }

    private function alertSuperAdmins(Dispute $dispute, string $detail, string $reason): void
    {
        $this->alertAdmins($dispute, [AdminRole::SuperAdmin], $reason, $detail);
    }

    /**
     * @param  list<AdminRole>  $roles
     * @param  list<AdminRole>  $fallbackRoles  used when nobody holds $roles
     */
    private function alertAdmins(Dispute $dispute, array $roles, string $reason, string $detail, array $fallbackRoles = []): void
    {
        DB::afterCommit(function () use ($dispute, $roles, $reason, $detail, $fallbackRoles) {
            try {
                $find = fn (array $roles) => User::where('type', UserType::Admin)
                    ->whereIn('admin_role', array_map(fn (AdminRole $r) => $r->value, $roles))
                    ->where('is_active', true)
                    ->get();

                $admins = $find($roles);

                if ($admins->isEmpty() && $fallbackRoles !== []) {
                    $admins = $find($fallbackRoles);
                }

                Notification::send($admins, new DisputeAlertNotification($dispute, $reason, $detail));
            } catch (\Throwable $e) {
                Log::warning('Dispute admin alert failed', ['dispute_id' => $dispute->id, 'error' => $e->getMessage()]);
            }
        });
    }
}
