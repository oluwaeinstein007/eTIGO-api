<?php

namespace App\Services;

use App\Enums\AdminRole;
use App\Enums\DisputeQueue;
use App\Enums\DisputeRefundMethod;
use App\Models\AppSetting;
use App\Models\Dispute;
use App\Models\DisputeRefundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who can see and work which dispute (OQ-21).
 *
 * - Support queue: tier 1 is `support`, tier 2 `support_lead`, tier 3
 *   `super_admin`. A higher tier can work a lower tier's case, never the
 *   other way round. Both support roles can read every support case.
 * - Safety queue: `safety_operator` below tier 3, then `super_admin`. The
 *   Safety team can read every case.
 * - On an appeal, whoever made the original decision cannot decide again.
 * - Evidence (NF-12): the assigned reviewer, higher tiers, Safety and super
 *   admins only; an unassigned reviewer takes the case to see it.
 */
class DisputeAccess
{
    /**
     * The tier an admin works at, or null if they do not work disputes.
     */
    public function roleTier(User $admin): ?int
    {
        return match ($admin->admin_role) {
            AdminRole::Support => 1,
            AdminRole::SupportLead, AdminRole::SafetyOperator => 2,
            AdminRole::SuperAdmin => 3,
            default => null,
        };
    }

    public function canView(User $admin, Dispute $dispute): bool
    {
        return match ($admin->admin_role) {
            AdminRole::SuperAdmin, AdminRole::SafetyOperator => true,
            AdminRole::Support, AdminRole::SupportLead => $dispute->queue === DisputeQueue::Support,
            default => false,
        };
    }

    /**
     * Limit a dispute query to what the admin can see.
     */
    public function scopeVisible(Builder $query, User $admin): Builder
    {
        return match ($admin->admin_role) {
            AdminRole::SuperAdmin, AdminRole::SafetyOperator => $query,
            AdminRole::Support, AdminRole::SupportLead => $query->where('queue', DisputeQueue::Support),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Why the admin cannot work (take, escalate, decide) this case, or null
     * when they can. Does not check whether the case is still open.
     */
    public function actDenial(User $admin, Dispute $dispute): ?string
    {
        if (in_array($admin->id, $dispute->excludedReviewerIds(), true)) {
            return 'You made the original decision on this case, so the appeal goes to a different reviewer.';
        }

        if ($admin->admin_role === AdminRole::SuperAdmin) {
            return null;
        }

        if ($dispute->escalation_tier >= 3) {
            return 'This case is at tier 3 and only a super admin can work it.';
        }

        if ($dispute->queue === DisputeQueue::Safety) {
            return $admin->admin_role === AdminRole::SafetyOperator
                ? null
                : 'Safety cases are handled by the Safety team.';
        }

        $tier = match ($admin->admin_role) {
            AdminRole::Support => 1,
            AdminRole::SupportLead => 2,
            default => null,
        };

        if ($tier === null) {
            return 'Your role cannot work support cases.';
        }

        return $tier >= $dispute->escalation_tier
            ? null
            : "This case is at tier {$dispute->escalation_tier}, above your tier.";
    }

    public function canAct(User $admin, Dispute $dispute): bool
    {
        return $this->actDenial($admin, $dispute) === null;
    }

    /**
     * Whether the admin may be given the case: active, and able to work it.
     */
    public function canBeAssigned(User $admin, Dispute $dispute): bool
    {
        return $admin->isAdmin() && $admin->is_active !== false && $this->canAct($admin, $dispute);
    }

    /**
     * Whether the admin can hand cases to other people (leads, Safety and
     * super admins); tier 1 agents can only take a case themselves.
     */
    public function canAssignOthers(User $admin): bool
    {
        return in_array($admin->admin_role, [AdminRole::SuperAdmin, AdminRole::SupportLead, AdminRole::SafetyOperator], true);
    }

    public function canViewEvidence(User $admin, Dispute $dispute): bool
    {
        if (in_array($admin->admin_role, [AdminRole::SuperAdmin, AdminRole::SafetyOperator], true)) {
            return true;
        }

        if ($dispute->assigned_admin_id === $admin->id) {
            return true;
        }

        $tier = $this->roleTier($admin);

        return $dispute->queue === DisputeQueue::Support
            && $tier !== null
            && $tier > $dispute->escalation_tier;
    }

    /**
     * Most the admin can refund on one dispute without a second approver,
     * in NGN; null means no limit.
     *
     * @param  array<string, mixed>|null  $settings  AppSetting::disputes(), when the caller already has it
     */
    public function refundLimit(User $admin, ?array $settings = null): ?float
    {
        $settings ??= AppSetting::disputes();

        return match ($admin->admin_role) {
            AdminRole::SuperAdmin => null,
            AdminRole::SupportLead => $settings['tier_2_refund_limit'],
            AdminRole::SafetyOperator => $settings['safety_refund_limit'],
            AdminRole::Support => $settings['tier_1_refund_limit'],
            default => 0.0,
        };
    }

    public function withinRefundLimit(User $admin, float $totalRefund): bool
    {
        $limit = $this->refundLimit($admin);

        return $limit === null || $totalRefund <= $limit + 0.001;
    }

    /**
     * Why the admin cannot approve or reject this refund request, or null
     * when they can. A card refund needs Finance (or a super admin); a
     * wallet refund needs an admin whose limit covers the dispute's total.
     */
    public function approvalDenial(User $admin, DisputeRefundRequest $request): ?string
    {
        $dispute = $request->dispute;

        if ($request->requested_by_admin_id === $admin->id) {
            return 'You cannot approve your own refund request.';
        }

        if (in_array($admin->id, $dispute->excludedReviewerIds(), true)) {
            return 'You made the original decision on this case, so someone else must approve the refund.';
        }

        if ($admin->admin_role === AdminRole::SuperAdmin) {
            return null;
        }

        if ($request->method === DisputeRefundMethod::Card) {
            return $admin->admin_role === AdminRole::Finance
                ? null
                : 'A refund to the card must be approved by Finance.';
        }

        if ($admin->admin_role === AdminRole::Finance) {
            return null;
        }

        // The case's own team: the Safety team for safety cases, the support
        // roles for support cases.
        $ownTeam = $dispute->queue === DisputeQueue::Safety
            ? $admin->admin_role === AdminRole::SafetyOperator
            : in_array($admin->admin_role, [AdminRole::Support, AdminRole::SupportLead], true);

        if (! $ownTeam) {
            return 'Your role cannot approve refunds on this case.';
        }

        $total = (float) $request->amount + (float) ($dispute->refund_amount ?? 0);

        return $this->withinRefundLimit($admin, $total)
            ? null
            : 'This refund is above your limit; it needs a higher tier.';
    }
}
