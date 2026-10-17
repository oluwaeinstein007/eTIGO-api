<?php

namespace App\Models;

use App\Enums\DisputeOutcome;
use App\Enums\OfflineFlagStatus;
use App\Enums\SanctionTier;
use App\Traits\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineTripFlag extends Model
{
    use HasFactory, HasReferenceNumber, HasUuids;

    protected $fillable = [
        'reference_number',
        'ride_id',
        'driver_id',
        'passenger_id',
        'detection_data',
        'sanction_tier',
        'sanction_action',
        'is_disputed',
        'dispute_notes',
        'disputed_at',
        'dispute_channel',
        'dispute_resolved_by_admin_id',
        'dispute_outcome',
        'review_notes',
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
            'disputed_at' => 'datetime',
            'flagged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function referencePrefix(): string
    {
        return 'FLG-';
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

    /**
     * Flags that still count against the driver: everything except a flag an
     * admin overturned, whether or not the driver disputed it.
     */
    public function scopeNotOverturned(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('dispute_outcome')
                ->orWhere('dispute_outcome', '!=', DisputeOutcome::Overturned);
        });
    }

    public function scopeWithStatus(Builder $query, OfflineFlagStatus $status): Builder
    {
        return match ($status) {
            OfflineFlagStatus::Upheld => $query->where('dispute_outcome', DisputeOutcome::Upheld),
            OfflineFlagStatus::Overturned => $query->where('dispute_outcome', DisputeOutcome::Overturned),
            OfflineFlagStatus::Disputed => $query->disputed(),
            OfflineFlagStatus::Flagged => $query->where('is_disputed', false)
                ->where(fn (Builder $q) => $q->whereNull('dispute_outcome')->orWhere('dispute_outcome', DisputeOutcome::Pending)),
        };
    }

    /**
     * Match the flag reference, the ride reference, or the driver's name,
     * phone or driver ID.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('reference_number', 'ilike', $like)
                ->orWhereHas('ride', fn (Builder $ride) => $ride->where('reference_number', 'ilike', $like))
                ->orWhereHas('driver', fn (Builder $driver) => $driver->where(fn (Builder $q) => $q
                    ->where('first_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", [$like])
                    ->orWhere('phone', 'ilike', $like)
                    ->orWhere('reference_number', 'ilike', $like)
                    ->orWhereHas('driver', fn (Builder $profile) => $profile->where('reference_number', 'ilike', $like))));
        });
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

    /**
     * Whether an admin has made the final call (upheld or overturned).
     */
    public function hasReviewOutcome(): bool
    {
        return in_array($this->dispute_outcome, [DisputeOutcome::Upheld, DisputeOutcome::Overturned], true);
    }

    /**
     * The evidence figures the compliance screen shows, from detection_data.
     * Flags raised before a figure was recorded have null for it.
     *
     * @return array{route_match_percentage: float|null, location_samples: int|null, on_route_samples: int|null, threshold_meters: int|null, cancel_distance_from_pickup_km: float|null, on_route_duration_seconds: int|null, average_gps_accuracy_meters: float|null}
     */
    public function evidence(): array
    {
        $data = $this->detection_data ?? [];
        $distance = $data['cancel_distance_from_pickup_meters'] ?? null;

        return [
            'route_match_percentage' => isset($data['route_match_percentage']) ? (float) $data['route_match_percentage'] : null,
            'location_samples' => isset($data['total_trajectory_points']) ? (int) $data['total_trajectory_points'] : null,
            'on_route_samples' => isset($data['collocation_points']) ? (int) $data['collocation_points'] : null,
            'threshold_meters' => isset($data['threshold_meters']) ? (int) $data['threshold_meters'] : null,
            'cancel_distance_from_pickup_km' => $distance !== null ? round($distance / 1000, 2) : null,
            'on_route_duration_seconds' => isset($data['on_route_duration_seconds']) ? (int) $data['on_route_duration_seconds'] : null,
            'average_gps_accuracy_meters' => isset($data['average_gps_accuracy_meters']) ? (float) $data['average_gps_accuracy_meters'] : null,
        ];
    }

    /**
     * Why the trip was flagged, in a sentence an admin can read to the driver.
     */
    public function detectionSummary(): ?string
    {
        $evidence = $this->evidence();

        if ($evidence['route_match_percentage'] === null) {
            return null;
        }

        $opening = $evidence['cancel_distance_from_pickup_km'] !== null
            ? "The ride was cancelled with the driver {$evidence['cancel_distance_from_pickup_km']} km from the pickup. Afterwards, "
            : 'After the ride was cancelled, ';

        $sentence = $opening."the driver's phone followed the passenger's route: "
            .rtrim(rtrim(number_format($evidence['route_match_percentage'], 1), '0'), '.').'% of its location samples';

        if ($evidence['on_route_samples'] !== null && $evidence['location_samples'] !== null) {
            $sentence .= " ({$evidence['on_route_samples']} of {$evidence['location_samples']})";
        }

        if ($evidence['threshold_meters'] !== null) {
            $sentence .= " were within {$evidence['threshold_meters']} m of it";
        } else {
            $sentence .= ' were on it';
        }

        if ($evidence['on_route_duration_seconds']) {
            $minutes = intdiv($evidence['on_route_duration_seconds'], 60);
            $seconds = $evidence['on_route_duration_seconds'] % 60;
            $sentence .= ", over {$minutes} min {$seconds} s";
        }

        return $sentence.'.';
    }

    public function status(): OfflineFlagStatus
    {
        return match (true) {
            $this->dispute_outcome === DisputeOutcome::Upheld => OfflineFlagStatus::Upheld,
            $this->dispute_outcome === DisputeOutcome::Overturned => OfflineFlagStatus::Overturned,
            $this->is_disputed => OfflineFlagStatus::Disputed,
            default => OfflineFlagStatus::Flagged,
        };
    }
}
