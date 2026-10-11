<?php

namespace App\Models;

use App\Enums\EvStallStatus;
use App\Enums\EvStationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvChargingStation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'city_id',
        'lat',
        'lng',
        'address',
        'total_stalls',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'total_stalls' => 'integer',
            'status' => EvStationStatus::class,
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function stalls(): HasMany
    {
        return $this->hasMany(EvChargingStall::class, 'station_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(EvReservation::class, 'station_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EvStationStatus::Active);
    }

    public function scopeForCity(Builder $query, string $cityId): Builder
    {
        return $query->where('city_id', $cityId);
    }

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }

    public function availableStallsCount(): int
    {
        return $this->stalls()->where('status', EvStallStatus::Available)->count();
    }
}
