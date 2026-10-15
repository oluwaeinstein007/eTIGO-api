<?php

namespace App\Models;

use App\Enums\FuelType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'driver_id',
        'make',
        'model',
        'colour',
        'plate_number',
        'year',
        'fuel_type',
        'is_fleet',
        'vehicle_class_id',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'fuel_type' => FuelType::class,
            'is_fleet' => 'boolean',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function fleetAgreements(): HasMany
    {
        return $this->hasMany(FleetAgreement::class);
    }

    public function isAssigned(): bool
    {
        return $this->driver_id !== null;
    }

    public function scopeFleetInventory(Builder $query): void
    {
        $query->where('is_fleet', true)->whereNull('driver_id');
    }

    public function scopeFleet(Builder $query): void
    {
        $query->where('is_fleet', true);
    }
}
