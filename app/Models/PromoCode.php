<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'city_id',
        'vehicle_class_id',
        'minimum_fare_amount',
        'is_active',
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
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
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
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now());
    }

    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', strtoupper(trim($code)));
    }

    public function isWithinTimeWindow(): bool
    {
        $now = now();

        return $this->is_active
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
