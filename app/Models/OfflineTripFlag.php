<?php

namespace App\Models;

use App\Enums\DisputeOutcome;
use App\Enums\SanctionTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineTripFlag extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'ride_id',
        'driver_id',
        'passenger_id',
        'detection_data',
        'sanction_tier',
        'sanction_action',
        'is_disputed',
        'dispute_notes',
        'dispute_resolved_by_admin_id',
        'dispute_outcome',
        'flagged_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'detection_data' => 'array',
            'sanction_tier' => SanctionTier::class,
            'dispute_outcome' => DisputeOutcome::class,
            'is_disputed' => 'boolean',
            'flagged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispute_resolved_by_admin_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeDisputed(Builder $query): Builder
    {
        return $query->where('is_disputed', true)
            ->where('dispute_outcome', DisputeOutcome::Pending);
    }

    public function scopeForDriver(Builder $query, string $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    public function scopeWithinLookback(Builder $query, ?int $days = null): Builder
    {
        $days ??= config('offline_detection.sanction_lookback_days', 30);

        return $query->where('flagged_at', '>=', now()->subDays($days));
    }

    public function isDisputed(): bool
    {
        return $this->is_disputed;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function isOverturned(): bool
    {
        return $this->dispute_outcome === DisputeOutcome::Overturned;
    }
}
