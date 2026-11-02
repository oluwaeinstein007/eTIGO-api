<?php

namespace App\Services;

use App\Enums\DisputeQueue;
use App\Enums\DisputeStatus;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The audit trail on the admin dispute page: who submitted, took, assigned,
 * escalated, sent back, decided and appealed the case, and when, oldest
 * first. Evidence views are access logs, not steps, and are left out.
 */
class DisputeHistoryService
{
    public const LIMIT = 100;

    private const EVENTS = [
        'dispute_assigned',
        'dispute_unassigned',
        'dispute_returned_to_queue',
        'dispute_status_changed',
        'dispute_escalated',
        'dispute_auto_escalated',
        'dispute_deescalated',
        'dispute_linked_synced',
        'dispute_target_missed',
        'dispute_evidence_added',
        'dispute_refund_requested',
        'dispute_refund_approved',
        'dispute_refund_rejected',
        'dispute_refund_withdrawn',
        'dispute_resolved',
        'dispute_appealed',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Dispute $dispute): array
    {
        // The newest entries when a case has more than the limit, shown in
        // the order they happened. Ids are time-ordered, which settles
        // entries made in the same second.
        $logs = AuditLog::query()
            ->where('auditable_type', $dispute->getMorphClass())
            ->where('auditable_id', $dispute->id)
            ->whereIn('event', self::EVENTS)
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->reverse()
            ->values();

        $assigneeIds = $logs->map(fn (AuditLog $log) => $log->new_values['assigned_admin_id'] ?? null)->filter()->unique();
        $assignees = User::whereIn('id', $assigneeIds)->get()->keyBy('id');

        $entries = $logs->map(fn (AuditLog $log) => $this->entry($log, $assignees))->all();

        // Filing is logged against the trip, so the case itself gives the
        // first step. Left out when older steps were cut off by the limit.
        if ($logs->count() < self::LIMIT) {
            array_unshift($entries, $this->submitted($dispute));
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function submitted(Dispute $dispute): array
    {
        $reporter = $dispute->relationLoaded('reportedBy') ? $dispute->reportedBy : $dispute->reportedBy()->first();

        return [
            'id' => null,
            'event' => 'dispute_filed',
            'description' => 'Submitted the case',
            'reason' => null,
            'actor' => $this->person($reporter),
            'by_system' => false,
            'assignee' => null,
            'routing' => null,
            'amount' => null,
            'created_at' => $dispute->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<string, User>  $assignees
     * @return array<string, mixed>
     */
    private function entry(AuditLog $log, Collection $assignees): array
    {
        $new = $log->new_values ?? [];
        $old = $log->old_values ?? [];
        $routing = $this->routing($new);
        $assigneeId = $new['assigned_admin_id'] ?? null;
        $assignee = $assigneeId ? $assignees->get($assigneeId) : null;
        $tookItSelf = $assigneeId !== null && $assigneeId === $log->actor_id;

        return [
            'id' => $log->id,
            'event' => $log->event,
            'description' => $this->describe($log, $new, $old, $routing, $assignee, $tookItSelf),
            'reason' => $new['reason'] ?? $new['note'] ?? $new['notes'] ?? $new['resolution_notes'] ?? null,
            // Null for the system, and for an admin deleted since (by_system
            // tells the two apart).
            'actor' => $this->person($log->actor),
            'by_system' => $log->actor_id === null && $log->actor_type === null,
            'assignee' => in_array($log->event, ['dispute_assigned', 'dispute_linked_synced'], true) && $assigneeId !== null ? [
                'id' => $assigneeId,
                'name' => $assignee ? $this->name($assignee) : null,
            ] : null,
            // Where the case went: escalations, returns, appeals and moves
            // with the linked case.
            'routing' => $routing,
            'amount' => isset($new['amount']) ? (float) $new['amount'] : (isset($new['refund_amount']) ? (float) $new['refund_amount'] : null),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>|null  $routing
     */
    private function describe(AuditLog $log, array $new, array $old, ?array $routing, ?User $assignee, bool $tookItSelf): string
    {
        $to = $routing ? " to {$routing['label']}" : '';
        $amount = fn (string $key) => isset($new[$key]) ? ' of ₦'.number_format((float) $new[$key], 2) : '';

        return match ($log->event) {
            'dispute_assigned' => $tookItSelf ? 'Took the case' : 'Assigned the case'.($assignee ? ' to '.$this->name($assignee) : ''),
            'dispute_unassigned' => 'Returned the case to the queue',
            'dispute_returned_to_queue' => 'Case returned to the queue',
            'dispute_status_changed' => 'Marked the case under review',
            'dispute_escalated' => ($routing['queue'] ?? null) === DisputeQueue::Safety->value && ($old['queue'] ?? null) !== DisputeQueue::Safety->value
                ? 'Sent the case to the Safety team'
                : 'Escalated the case'.$to,
            'dispute_auto_escalated' => 'Escalated automatically'.$to,
            'dispute_deescalated' => 'Sent the case back'.$to,
            'dispute_linked_synced' => 'Moved with the other side\'s case'.$to,
            'dispute_target_missed' => 'Missed the '.($new['target'] ?? 'response').' target',
            'dispute_evidence_added' => 'Added '.($new['files'] ?? 1).' evidence '.(($new['files'] ?? 1) === 1 ? 'file' : 'files'),
            'dispute_refund_requested' => 'Asked for approval of a refund'.$amount('amount'),
            'dispute_refund_approved' => 'Approved the refund'.$amount('amount'),
            'dispute_refund_rejected' => 'Rejected the refund request',
            'dispute_refund_withdrawn' => 'Withdrew the refund request',
            'dispute_resolved' => (DisputeStatus::tryFrom($new['status'] ?? '') === DisputeStatus::Dismissed ? 'Dismissed the case' : 'Resolved the case')
                .(isset($new['refund_amount']) ? ' with a refund'.$amount('refund_amount') : ''),
            'dispute_appealed' => 'Appealed the decision'.$to,
            default => $log->event,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{queue: string, escalation_tier: int, label: string}|null
     */
    private function routing(array $values): ?array
    {
        $queue = DisputeQueue::tryFrom($values['queue'] ?? '');

        if ($queue === null || ! isset($values['escalation_tier'])) {
            return null;
        }

        return [
            'queue' => $queue->value,
            'escalation_tier' => (int) $values['escalation_tier'],
            'label' => Dispute::labelForTier($queue, (int) $values['escalation_tier']),
        ];
    }

    /**
     * @return array{id: string, name: string, role: ?string}|null
     */
    private function person(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $this->name($user),
            'role' => $user->admin_role?->value ?? $user->type?->value,
        ] : null;
    }

    private function name(User $user): string
    {
        return trim($user->first_name.' '.$user->last_name);
    }
}
