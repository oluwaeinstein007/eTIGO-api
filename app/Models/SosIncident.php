<?php

namespace App\Models;

use App\Enums\SosIncidentStatus;
use App\Enums\SosTriggerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SosIncident extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
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
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
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

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
