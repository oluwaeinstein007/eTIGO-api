<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleClass extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'capacity',
        'icon',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'city_vehicle_classes')
            ->withPivot('is_active', 'sort_order');
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function pricingConfigs(): HasMany
    {
        return $this->hasMany(PricingConfig::class);
    }
}
