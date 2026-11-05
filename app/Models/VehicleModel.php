<?php

namespace App\Models;

use App\Enums\FuelType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleModel extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'vehicle_make_id',
        'name',
        'fuel_type',
        'vehicle_class_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'vehicle_make_id');
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeForFuelType(Builder $query, FuelType|string $fuelType): void
    {
        $value = $fuelType instanceof FuelType ? $fuelType->value : $fuelType;
        $query->where('fuel_type', $value);
    }
}
