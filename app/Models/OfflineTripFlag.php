<?php

namespace App\Models;

use App\Enums\AdminRole;
use App\Enums\DisputeOutcome;
use App\Enums\OfflineFlagStatus;
use App\Enums\SanctionTier;
use App\Traits\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class OfflineTripFlag extends Model
{
    use HasFactory, HasReferenceNumber, HasUuids;

    private const OVERTURNED_REFUSAL = 'This flag was overturned and no longer counts against the driver.';

    private const DEACTIVATED_REFUSAL = "The driver's deactivation was confirmed. To undo it, a super admin can overturn the flag on appeal.";

    protected $fillable = [
        'reference_number',
        'ride_id',
        'driver_id',
        'passenger_id',
        'detection_data',
        'sanction_tier',
        'sanction_action',
        'deactivation_response_due_at',
        'is_disputed',
        'dispute_notes',
        'disputed_at',
        'dispute_channel',
        'dispute_resolved_by_admin_id',
        'dispute_outcome',
        'review_notes',
        'deactivation_confirmed_at',
        'deactivation_confirmed_by_admin_id',
        'deactivation_notes',
        'deactivation_reversed_at',
        'cash_taking_verified_at',
        'cash_taking_verified_by_admin_id',
        'cash_taking_notes',
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
            'deactivation_response_due_at' => 'datetime',
            'deactivation_confirmed_at' => 'datetime',
            'deactivation_reversed_at' => 'datetime',
            'cash_taking_verified_at' => 'datetime',
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

    public function deactivationConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivation_confirmed_by_admin_id');
    }

    public function cashTakingVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_taking_verified_by_admin_id');
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
     * Whether an admin has made the call (upheld or overturned).
     */
    public function hasReviewOutcome(): bool
    {
        return in_array($this->dispute_outcome, [DisputeOutcome::Upheld, DisputeOutcome::Overturned], true);
    }

    /**
     * A confirmed Level 3 deactivation that no appeal has reversed.
     */
    public function hasStandingDeactivation(): bool
    {
        return $this->deactivation_confirmed_at !== null && $this->deactivation_reversed_at === null;
    }

    public function isCashTakingVerified(): bool
    {
        return $this->cash_taking_verified_at !== null;
    }

    /**
     * When a driver whose flag reaches Level 3 now must respond by.
     */
    public static function newResponseDeadline(): Carbon
    {
        return now()->addHours((int) config('offline_detection.deactivation_response_hours', 48));
    }

    /**
     * Whether the driver still has time to respond to this Level 3 flag
     * before anyone decides on it. Disputing ends the wait: the dispute is
     * then what the admin reviews.
     */
    public function awaitsDriverResponse(): bool
    {
        return $this->sanction_tier === SanctionTier::Deactivation
            && ! $this->is_disputed
            && (bool) $this->deactivation_response_due_at?->isFuture();
    }

    private function awaitingResponseRefusal(): string
    {
        $due = $this->deactivation_response_due_at->copy()->timezone('Africa/Lagos')->format('j M Y, H:i');

        return "The driver has until {$due} WAT to respond to this Level 3 flag. You can uphold it or confirm the deactivation after that, or as soon as they dispute it.";
    }

    /**
     * Why an admin may not uphold or overturn this flag, or null if they may.
     * An upheld flag can still be overturned on appeal; an overturn is final.
     */
    public function reviewRefusal(DisputeOutcome $outcome, User $admin): ?string
    {
        if ($outcome === DisputeOutcome::Upheld && $this->hasReviewOutcome()) {
            return 'This flag has already been reviewed.';
        }

        // Upholding would take away the driver's chance to dispute.
        if ($outcome === DisputeOutcome::Upheld && $this->awaitsDriverResponse()) {
            return $this->awaitingResponseRefusal();
        }

        if ($outcome === DisputeOutcome::Overturned && $this->isOverturned()) {
            return 'This flag has already been reviewed.';
        }

        // OQ-14: only a super admin can overturn a Level 3 deactivation.
        if ($outcome === DisputeOutcome::Overturned && $this->hasStandingDeactivation()
            && $admin->admin_role !== AdminRole::SuperAdmin) {
            return 'Only a super admin can overturn a flag whose deactivation was confirmed.';
        }

        return null;
    }

    public function escalateRefusal(): ?string
    {
        return match (true) {
            $this->isOverturned() => self::OVERTURNED_REFUSAL,
            $this->sanction_tier === SanctionTier::Deactivation => 'This flag is already at the maximum sanction tier.',
            // Escalating would suspend the driver again, and the suspension
            // would later lift: only a super admin's overturn undoes this.
            (bool) $this->driverProfile()?->isDeactivated() => 'The driver has been deactivated, so their flags cannot be escalated.',
            default => null,
        };
    }

    public function deEscalateRefusal(): ?string
    {
        return match (true) {
            $this->isOverturned() => self::OVERTURNED_REFUSAL,
            $this->sanction_tier === SanctionTier::Warning => 'This flag is already at the lowest sanction tier.',
            $this->hasStandingDeactivation(),
            $this->sanction_tier === SanctionTier::Deactivation && $this->driverProfile()?->isDeactivated() => self::DEACTIVATED_REFUSAL,
            default => null,
        };
    }

    /**
     * Why the driver's deactivation cannot be confirmed on this flag, or
     * null if it can. A missing driver profile is the controller's 404.
     * A super admin can confirm before the driver's response deadline.
     */
    public function confirmDeactivationRefusal(?User $admin = null): ?string
    {
        return match (true) {
            $this->sanction_tier !== SanctionTier::Deactivation => 'Only L3 (deactivation-tier) flags can be confirmed for permanent deactivation.',
            $this->isOverturned() => 'This flag was overturned and cannot lead to deactivation.',
            $this->is_disputed && $this->dispute_outcome === DisputeOutcome::Pending => "Review the driver's dispute before confirming deactivation.",
            $this->awaitsDriverResponse() && $admin?->admin_role !== AdminRole::SuperAdmin => $this->awaitingResponseRefusal(),
            $this->hasStandingDeactivation() => 'Deactivation has already been confirmed for this flag.',
            (bool) $this->driverProfile()?->isDeactivated() => 'Driver is already deactivated.',
            default => null,
        };
    }

    public function cashTakingRefusal(): ?string
    {
        return match (true) {
            $this->isOverturned() => self::OVERTURNED_REFUSAL,
            $this->isCashTakingVerified() => 'Cash-taking has already been verified on this flag.',
            $this->hasStandingDeactivation(), (bool) $this->driverProfile()?->isDeactivated() => self::DEACTIVATED_REFUSAL,
            default => null,
        };
    }

    /**
     * Which actions the admin can take on the flag now, so the compliance
     * screen shows only the buttons that will work.
     *
     * @return array<string, bool>
     */
    public function allowedActions(User $admin): array
    {
        return [
            'uphold' => $this->reviewRefusal(DisputeOutcome::Upheld, $admin) === null,
            'overturn' => $this->reviewRefusal(DisputeOutcome::Overturned, $admin) === null,
            'escalate' => $this->escalateRefusal() === null,
            'de_escalate' => $this->deEscalateRefusal() === null,
            'confirm_deactivation' => $this->confirmDeactivationRefusal($admin) === null,
            'verify_cash_taking' => $this->cashTakingRefusal() === null,
        ];
    }

    /**
     * The flagged driver's profile (the user's Driver record).
     */
    public function driverProfile(): ?Driver
    {
        return $this->driver?->driver;
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
     * What the compliance map draws: the passenger's pickup, destination and
     * route (the line the driver's positions were measured against, with the
     * corridor counted as on the route), where the driver was when the ride
     * was cancelled, and the driver's positions afterwards. Flags raised
     * before October 20, 2026 have no route, cancel point or path.
     *
     * @return array<string, mixed>
     */
    public function routeMap(): array
    {
        $data = $this->detection_data ?? [];
        $ride = $this->ride;
        $cancelledAt = isset($data['cancelled_at']) ? Carbon::parse($data['cancelled_at']) : null;

        $place = fn (string $prefix) => $ride ? [
            'lat' => (float) $ride->{"{$prefix}_lat"},
            'lng' => (float) $ride->{"{$prefix}_lng"},
            'address' => $ride->{"{$prefix}_address"},
        ] : null;

        return [
            'pickup' => $place('pickup'),
            'destination' => $place('destination'),
            'route' => isset($data['route_polyline']) ? [
                'encoded_polyline' => $data['route_polyline'],
                'source' => $data['route_source'] ?? 'straight_line',
            ] : null,
            'corridor_meters' => isset($data['threshold_meters']) ? (int) $data['threshold_meters'] : null,
            'cancelled_at' => $cancelledAt?->toISOString(),
            'cancel_point' => isset($data['cancel_lat'], $data['cancel_lng']) ? [
                'lat' => (float) $data['cancel_lat'],
                'lng' => (float) $data['cancel_lng'],
                'accuracy_meters' => isset($data['cancel_gps_accuracy_meters']) ? (float) $data['cancel_gps_accuracy_meters'] : null,
            ] : null,
            'driver_path' => array_map(fn (array $point) => [
                'lat' => (float) $point['lat'],
                'lng' => (float) $point['lng'],
                'recorded_at' => isset($point['timestamp']) ? Carbon::createFromTimestamp($point['timestamp'])->toISOString() : null,
                'seconds_after_cancellation' => isset($point['timestamp']) && $cancelledAt
                    ? (int) $point['timestamp'] - $cancelledAt->getTimestamp()
                    : null,
                'distance_from_route_meters' => $point['distance_from_route_meters'] ?? null,
                'on_route' => (bool) ($point['on_route'] ?? false),
                'accuracy_meters' => isset($point['accuracy']) ? (float) $point['accuracy'] : null,
            ], $data['driver_path'] ?? []),
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
