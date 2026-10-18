<?php

namespace App\Models;

use App\Enums\DisputeCategory;
use App\Enums\DisputeQueue;
use App\Enums\DisputeRefundMethod;
use App\Enums\DisputeRefundRequestStatus;
use App\Enums\DisputeSlaStatus;
use App\Enums\DisputeStatus;
use App\Traits\HasReferenceNumber;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Dispute extends Model
{
    use HasFactory, HasReferenceNumber, HasUuids;

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_APPEAL = 'appeal';

    protected $fillable = [
        'ride_id',
        'reported_by_user_id',
        'category',
        'description',
        'evidence_photos',
        'evidence_audio',
        'status',
        'queue',
        'escalation_tier',
        'assigned_admin_id',
        'assigned_at',
        'escalated_by_admin_id',
        'escalated_at',
        'escalation_reason',
        'escalation_source',
        'sla_started_at',
        'first_response_due_at',
        'resolution_due_at',
        'first_responded_at',
        'first_response_breached_at',
        'resolution_breached_at',
        'resolution_notes',
        'refund_amount',
        'refund_currency',
        'driver_clawback_amount',
        'refund_method',
        'resolved_by_admin_id',
        'resolved_at',
        'decision_tier',
        'appealed_at',
        'appeal_reason',
        'appeal_of_resolver_id',
        'original_decision',
        'linked_dispute_id',
        'sos_incident_id',
        'reference_number',
    ];

    protected $attributes = [
        'queue' => 'support',
        'escalation_tier' => 1,
    ];

    protected static function referencePrefix(): string
    {
        return 'DPT-';
    }

    protected function casts(): array
    {
        return [
            'category' => DisputeCategory::class,
            'status' => DisputeStatus::class,
            'queue' => DisputeQueue::class,
            'escalation_tier' => 'integer',
            'decision_tier' => 'integer',
            'evidence_photos' => 'array',
            'refund_amount' => 'decimal:2',
            'driver_clawback_amount' => 'decimal:2',
            'refund_method' => DisputeRefundMethod::class,
            'original_decision' => 'array',
            'assigned_at' => 'datetime',
            'escalated_at' => 'datetime',
            'sla_started_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'first_response_breached_at' => 'datetime',
            'resolution_breached_at' => 'datetime',
            'resolved_at' => 'datetime',
            'appealed_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_admin_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by_admin_id');
    }

    public function appealOfResolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appeal_of_resolver_id');
    }

    public function linkedDispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class, 'linked_dispute_id');
    }

    public function sosIncident(): BelongsTo
    {
        return $this->belongsTo(SosIncident::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(DisputeNote::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class)->orderBy('uploaded_at');
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(DisputeRefundRequest::class)->latest();
    }

    public function pendingRefundRequest(): HasOne
    {
        return $this->hasOne(DisputeRefundRequest::class)
            ->where('status', DisputeRefundRequestStatus::Pending);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [DisputeStatus::Open, DisputeStatus::UnderReview]);
    }

    public function isOpen(): bool
    {
        return ! $this->status->isTerminal();
    }

    public function isAppeal(): bool
    {
        return $this->appealed_at !== null;
    }

    public function tierLabel(): string
    {
        if ($this->queue === DisputeQueue::Safety && $this->escalation_tier < 3) {
            return 'Safety team';
        }

        return match ($this->escalation_tier) {
            1 => 'Tier 1 — Support',
            2 => 'Tier 2 — Support lead',
            default => 'Tier 3 — Management',
        };
    }

    /**
     * What the reporter is told about who has their case. Never names an
     * admin (OQ-21).
     */
    public function stageLabel(): string
    {
        if ($this->status->isTerminal()) {
            return 'Decision made';
        }

        if ($this->queue === DisputeQueue::Safety && $this->escalation_tier < 3) {
            return 'With our safety team';
        }

        return $this->escalation_tier > 1 ? 'With our senior team' : 'With our support team';
    }

    /**
     * The next target the case has to meet, while it is open.
     */
    public function nextDueAt(): ?CarbonInterface
    {
        if (! $this->isOpen()) {
            return null;
        }

        return $this->first_responded_at === null && $this->first_response_due_at !== null
            ? $this->first_response_due_at
            : $this->resolution_due_at;
    }

    public function slaStatus(): DisputeSlaStatus
    {
        if (! $this->isOpen()) {
            if ($this->resolution_due_at === null || $this->resolved_at === null) {
                return DisputeSlaStatus::None;
            }

            return $this->resolved_at->lte($this->resolution_due_at) ? DisputeSlaStatus::Met : DisputeSlaStatus::Missed;
        }

        $due = $this->nextDueAt();

        if ($due === null) {
            return DisputeSlaStatus::None;
        }

        if (now()->gte($due)) {
            return DisputeSlaStatus::Breached;
        }

        $start = $this->sla_started_at ?? $this->created_at;
        $window = max(1, $start->diffInSeconds($due));

        return now()->diffInSeconds($due) < $window / 4 ? DisputeSlaStatus::AtRisk : DisputeSlaStatus::OnTrack;
    }

    /**
     * @param  array<string, mixed>|null  $settings  AppSetting::disputes(), when the caller already has it
     */
    public function appealDeadline(?array $settings = null): ?CarbonInterface
    {
        if (! $this->status->isTerminal() || $this->resolved_at === null) {
            return null;
        }

        return $this->resolved_at->copy()->addDays(($settings ?? AppSetting::disputes())['appeal_window_days']);
    }

    /**
     * Why the reporter cannot appeal, or null when they can: once, within
     * the window, and not against a tier 3 decision, which is final.
     *
     * @param  array<string, mixed>|null  $settings  AppSetting::disputes(), when the caller already has it
     */
    public function appealBlocker(?array $settings = null): ?string
    {
        if (! $this->status->isTerminal()) {
            return 'You can appeal once a decision has been made.';
        }

        if ($this->appealed_at !== null) {
            return 'This decision has already been appealed once.';
        }

        if (($this->decision_tier ?? $this->escalation_tier) >= 3) {
            return 'This decision was made by our management team and is final.';
        }

        $deadline = $this->appealDeadline($settings);

        if ($deadline === null || now()->gt($deadline)) {
            return 'The time to appeal this decision has passed.';
        }

        return null;
    }

    /**
     * Admins who may not decide this case: on an appeal, whoever decided
     * (and approved the refund on) the original decision.
     *
     * @return list<string>
     */
    public function excludedReviewerIds(): array
    {
        return array_values(array_filter(array_unique([
            $this->appeal_of_resolver_id,
            $this->original_decision['refund_approved_by_admin_id'] ?? null,
        ])));
    }
}
