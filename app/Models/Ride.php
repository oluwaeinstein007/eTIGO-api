<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RiderNotificationStatus;
use App\Enums\RideStatus;
use App\Enums\ThirdPartyRecipient;
use App\Traits\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Ride extends Model
{
    use HasFactory, HasReferenceNumber, HasUuids;

    protected static function referencePrefix(): string
    {
        return 'TRP-NG-';
    }

    protected $fillable = [
        'city_id',
        'vehicle_class_id',
        'passenger_id',
        'is_third_party',
        'rider_name',
        'rider_phone',
        'pin_recipient',
        'tracking_recipient',
        'rider_notification_status',
        'rider_notification_channel',
        'rider_notified_at',
        'driver_id',
        'pickup_lat',
        'pickup_lng',
        'pickup_address',
        'destination_lat',
        'destination_lng',
        'destination_address',
        'status',
        'share_token',
        'fare_estimate_amount',
        'final_fare_amount',
        'fare_currency',
        'pricing_snapshot',
        'promo_code_id',
        'discount_amount',
        'payment_method',
        'payment_status',
        'cancelled_by',
        'cancellation_reason',
        'cancellation_details',
        'matched_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RideStatus::class,
            'payment_method' => PaymentMethod::class,
            'is_third_party' => 'boolean',
            'pin_recipient' => ThirdPartyRecipient::class,
            'tracking_recipient' => ThirdPartyRecipient::class,
            'rider_notification_status' => RiderNotificationStatus::class,
            'rider_notified_at' => 'datetime',
            'pickup_lat' => 'decimal:7',
            'pickup_lng' => 'decimal:7',
            'destination_lat' => 'decimal:7',
            'destination_lng' => 'decimal:7',
            'fare_estimate_amount' => 'decimal:2',
            'final_fare_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'pricing_snapshot' => 'array',
            'matched_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected function paymentStatus(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null) {
                    return null;
                }

                if ($value instanceof PaymentStatus) {
                    return $value;
                }

                if ($value === 'paid' || $value === 'successful') {
                    return PaymentStatus::Settled;
                }

                return PaymentStatus::tryFrom((string) $value);
            },
            set: fn ($value) => $value instanceof PaymentStatus ? $value->value : $value,
        );
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function promoRedemption(): HasOne
    {
        return $this->hasOne(PromoRedemption::class);
    }

    public function scheduledRide(): HasOne
    {
        return $this->hasOne(ScheduledRide::class);
    }

    public function stateTransitions(): HasMany
    {
        return $this->hasMany(RideStateTransition::class)->orderBy('created_at');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function sosIncidents(): HasMany
    {
        return $this->hasMany(SosIncident::class);
    }

    public function offlineTripFlags(): HasMany
    {
        return $this->hasMany(OfflineTripFlag::class);
    }

    public function carbonScores(): HasMany
    {
        return $this->hasMany(TripCarbonScore::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isCancellable(): bool
    {
        return $this->status->isCancellable();
    }

    /**
     * Number of times this ride has entered the searching state (1 for the
     * initial request, +1 for every passenger retry after no_driver_found).
     */
    public function searchAttemptCount(): int
    {
        return RideStateTransition::where('ride_id', $this->id)
            ->where('to_state', RideStatus::Searching->value)
            ->count();
    }

    public function belongsToPassenger(User $user): bool
    {
        return $this->passenger_id === $user->id;
    }

    public function isAssignedToDriver(string $driverId): bool
    {
        return $this->driver_id === $driverId;
    }

    /**
     * Rides the passenger takes themselves, not ones booked for someone else.
     */
    public function scopeOwnTrips(Builder $query): void
    {
        $query->where('is_third_party', false);
    }

    public function scopeThirdParty(Builder $query): void
    {
        $query->where('is_third_party', true);
    }

    /**
     * Whether the booker may see the ride PIN: the policy includes them, or
     * the rider's message could not be delivered (so the ride can still start).
     */
    public function bookerSeesPin(): bool
    {
        if (! $this->is_third_party) {
            return true;
        }

        return ($this->pin_recipient?->includesBooker() ?? true)
            || $this->rider_notification_status === RiderNotificationStatus::Failed;
    }

    /**
     * Whether the booker may follow the trip live (same fallback as the PIN).
     */
    public function bookerSeesTracking(): bool
    {
        if (! $this->is_third_party) {
            return true;
        }

        return ($this->tracking_recipient?->includesBooker() ?? true)
            || $this->rider_notification_status === RiderNotificationStatus::Failed;
    }

    /**
     * Whether the rider of a third-party booking is messaged at all.
     */
    public function messagesRider(): bool
    {
        return $this->is_third_party
            && ($this->pin_recipient?->includesRider() || $this->tracking_recipient?->includesRider());
    }

    /**
     * First name of the person being picked up: the rider on a third-party
     * booking, otherwise the passenger.
     */
    public function riderFirstName(): ?string
    {
        if ($this->is_third_party) {
            return Str::of((string) $this->rider_name)->trim()->before(' ')->toString() ?: null;
        }

        return $this->passenger?->first_name;
    }

    /**
     * Phone number of the person being picked up.
     */
    public function riderPhone(): ?string
    {
        return $this->is_third_party ? $this->rider_phone : $this->passenger?->phone;
    }
}
