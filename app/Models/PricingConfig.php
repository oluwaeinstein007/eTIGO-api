<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingConfig extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'city_id',
        'vehicle_class_id',
        'base_fare',
        'per_km_rate',
        'per_minute_rate',
        'minimum_fare',
        'waiting_time_rate',
        'free_waiting_minutes',
        'version',
        'effective_from',
        'created_by_admin_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'base_fare' => 'decimal:2',
            'per_km_rate' => 'decimal:2',
            'per_minute_rate' => 'decimal:2',
            'minimum_fare' => 'decimal:2',
            'waiting_time_rate' => 'decimal:2',
            'free_waiting_minutes' => 'integer',
            'version' => 'integer',
            'effective_from' => 'datetime',
            'created_at' => 'datetime',
        ];
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

    public function scopeEffective($query)
    {
        return $query->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->orderByDesc('version');
    }

    public function scopeForCityAndClass($query, int $cityId, int $vehicleClassId)
    {
        return $query->where('city_id', $cityId)
            ->where('vehicle_class_id', $vehicleClassId);
    }

    public static function currentFor(int $cityId, int $vehicleClassId): ?static
    {
        return static::forCityAndClass($cityId, $vehicleClassId)
            ->effective()
            ->first();
    }

    public function toSnapshot(): array
    {
        return [
            'pricing_config_id' => $this->id,
            'version' => $this->version,
            'base_fare' => $this->base_fare,
            'per_km_rate' => $this->per_km_rate,
            'per_minute_rate' => $this->per_minute_rate,
            'minimum_fare' => $this->minimum_fare,
            'waiting_time_rate' => $this->waiting_time_rate,
            'free_waiting_minutes' => $this->free_waiting_minutes,
            'effective_from' => $this->effective_from->toIso8601String(),
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
