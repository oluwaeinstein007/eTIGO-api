<?php

namespace App\Models;

use App\Enums\SosIncidentStatus;
use App\Enums\SosQueueStatus;
use App\Enums\SosTriggerType;
use App\Traits\HasReferenceNumber;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SosIncident extends Model
{
    use HasFactory, HasReferenceNumber, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'reference_number',
        'ride_id',
        'triggered_by_user_id',
        'trigger_type',
        'status',
        'gps_lat',
        'gps_lng',
        'vehicle_details',
        'telemetry_data',
        'check_in_sent_at',
        'check_in_acknowledged_at',
        'escalated_at',
        'operator_id',
        'operator_assigned_at',
        'operator_notes',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_type' => SosTriggerType::class,
            'status' => SosIncidentStatus::class,
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'vehicle_details' => 'encrypted:array',
            'telemetry_data' => 'encrypted:array',
            'check_in_sent_at' => 'datetime',
            'check_in_acknowledged_at' => 'datetime',
            'escalated_at' => 'datetime',
            'operator_assigned_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function referencePrefix(): string
    {
        return 'SOS-';
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(SosEventLog::class, 'incident_id')->orderBy('created_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            SosIncidentStatus::Resolved->value,
            SosIncidentStatus::Cancelled->value,
        ]);
    }

    public function scopeRequiringAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SosIncidentStatus::Triggered->value,
            SosIncidentStatus::CheckInSent->value,
            SosIncidentStatus::Escalated->value,
        ]);
    }

    public function scopeWithQueueStatus(Builder $query, SosQueueStatus $queueStatus): Builder
    {
        return $query->whereIn('status', $queueStatus->incidentStatuses());
    }

    /**
     * Match the incident reference, the ride reference, or the name or phone
     * of the passenger, the driver or whoever raised the SOS.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $matchesPerson = fn (Builder $q) => $q->where(function (Builder $q) use ($like) {
            $q->where('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", [$like])
                ->orWhere('phone', 'ilike', $like);
        });

        return $query->where(function (Builder $q) use ($like, $matchesPerson) {
            $q->where('reference_number', 'ilike', $like)
                ->orWhereHas('ride', fn (Builder $ride) => $ride->where('reference_number', 'ilike', $like))
                ->orWhereHas('ride.passenger', $matchesPerson)
                ->orWhereHas('ride.driver', $matchesPerson)
                ->orWhereHas('triggeredBy', $matchesPerson);
        });
    }

    public function queueStatus(): SosQueueStatus
    {
        return SosQueueStatus::fromIncidentStatus($this->status);
    }

    /**
     * How long the incident waited for an operator: until one took it (or
     * closed it), or until now while it is still waiting. Null for an incident
     * cancelled before any operator saw it.
     */
    public function waitSeconds(): ?int
    {
        $end = $this->operator_assigned_at
            ?? $this->resolved_at
            ?? ($this->isTerminal() ? null : now());

        return $end && $this->created_at ? (int) max(0, $this->created_at->diffInSeconds($end)) : null;
    }

    /**
     * When the incident escalates if the person who raised it does not answer
     * the check-in; null once it can no longer escalate automatically.
     */
    public function escalatesAt(): ?CarbonInterface
    {
        $timeout = (int) config('sos.escalation_timeout_seconds', 30);

        return match ($this->status) {
            SosIncidentStatus::CheckInSent => $this->check_in_sent_at?->copy()->addSeconds($timeout),
            SosIncidentStatus::Triggered => $this->created_at?->copy()
                ->addSeconds((int) config('sos.check_in_delay_seconds', 5) + $timeout),
            default => null,
        };
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
