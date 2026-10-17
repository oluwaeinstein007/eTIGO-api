<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PromoRiderEligibility;
use App\Enums\PromoStatus;
use App\Enums\PromoTiming;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code',
        'description',
        'discount_type',
        'discount_value',
        'max_discount_cap',
        'total_redemption_limit',
        'per_user_limit',
        'starts_at',
        'expires_at',
        'geo_fence',
        'min_order_count',
        'max_order_count',
        'min_tier_level',
        'peak_only',
        'off_peak_only',
        'vehicle_class_id',
        'minimum_fare_amount',
        'is_active',
        'cancelled_at',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'max_discount_cap' => 'decimal:2',
            'total_redemption_limit' => 'integer',
            'per_user_limit' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'geo_fence' => 'array',
            'min_order_count' => 'integer',
            'max_order_count' => 'integer',
            'min_tier_level' => 'integer',
            'peak_only' => 'boolean',
            'off_peak_only' => 'boolean',
            'minimum_fare_amount' => 'decimal:2',
            'is_active' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    /**
     * The cities the promo runs in; none means every city.
     */
    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class);
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('cancelled_at')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now());
    }

    /**
     * Promos that are, or would be, in the given dashboard status. Mirrors status().
     */
    public function scopeWithStatus(Builder $query, PromoStatus $status): Builder
    {
        return match ($status) {
            PromoStatus::Cancelled => $query->whereNotNull('cancelled_at'),
            PromoStatus::Expired => $query->whereNull('cancelled_at')->where('expires_at', '<=', now()),
            PromoStatus::Paused => $query->whereNull('cancelled_at')->where('expires_at', '>', now())->where('is_active', false),
            PromoStatus::Scheduled => $query->whereNull('cancelled_at')->where('is_active', true)->where('starts_at', '>', now()),
            PromoStatus::Active => $query->active(),
        };
    }

    public function scopeAvailableInCity(Builder $query, ?string $cityId): Builder
    {
        return $query->where(function (Builder $query) use ($cityId) {
            $query->whereDoesntHave('cities');

            if ($cityId) {
                $query->orWhereHas('cities', fn (Builder $q) => $q->whereKey($cityId));
            }
        });
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Where the promo stands for the dashboard: cancelled and expired win over
     * paused, and a promo that has not started yet is scheduled.
     */
    public function status(): PromoStatus
    {
        return match (true) {
            $this->isCancelled() => PromoStatus::Cancelled,
            $this->expires_at !== null && $this->expires_at->lte(now()) => PromoStatus::Expired,
            ! $this->is_active => PromoStatus::Paused,
            $this->starts_at !== null && $this->starts_at->isFuture() => PromoStatus::Scheduled,
            default => PromoStatus::Active,
        };
    }

    public function riderEligibility(): PromoRiderEligibility
    {
        return PromoRiderEligibility::fromOrderCounts($this->min_order_count, $this->max_order_count);
    }

    public function timing(): PromoTiming
    {
        return PromoTiming::fromFlags((bool) $this->peak_only, (bool) $this->off_peak_only);
    }

    /**
     * Whether the promo runs in the city; a promo with no cities runs everywhere.
     */
    public function isAvailableInCity(?string $cityId): bool
    {
        $cityIds = $this->relationLoaded('cities')
            ? $this->cities->modelKeys()
            : $this->cities()->pluck('cities.id')->all();

        return $cityIds === [] || ($cityId !== null && in_array($cityId, $cityIds, true));
    }

    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', strtoupper(trim($code)));
    }

    /**
     * @param  CarbonInterface|null  $at  Evaluate the window at this instant (defaults to now)
     */
    public function isWithinTimeWindow(?CarbonInterface $at = null): bool
    {
        $now = $at ?? now();

        return $this->is_active
            && ! $this->isCancelled()
            && $now->gte($this->starts_at)
            && $now->lt($this->expires_at);
    }

    public function hasReachedGlobalLimit(): bool
    {
        if ($this->total_redemption_limit === null) {
            return false;
        }

        return $this->redemptions()->count() >= $this->total_redemption_limit;
    }

    public function hasReachedUserLimit(string $userId): bool
    {
        return $this->redemptions()
            ->where('user_id', $userId)
            ->count() >= $this->per_user_limit;
    }

    public function totalRedemptions(): int
    {
        return $this->redemptions()->count();
    }

    public function totalDiscountGiven(): float
    {
        return (float) $this->redemptions()->sum('discount_amount');
    }
}
