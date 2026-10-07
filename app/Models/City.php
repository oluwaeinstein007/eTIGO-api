<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'boundary',
        'timezone',
        'currency_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'boundary' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function vehicleClasses(): BelongsToMany
    {
        return $this->belongsToMany(VehicleClass::class, 'city_vehicle_classes')
            ->withPivot('is_active', 'sort_order');
    }

    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class);
    }

    public function pricingConfigs(): HasMany
    {
        return $this->hasMany(PricingConfig::class);
    }

    public function evStations(): HasMany
    {
        return $this->hasMany(EvChargingStation::class);
    }
}
