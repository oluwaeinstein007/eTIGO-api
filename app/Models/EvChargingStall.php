<?php

namespace App\Models;

use App\Enums\EvStallStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvChargingStall extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'station_id',
        'stall_number',
        'status',
        'current_vehicle_driver_id',
        'occupied_since',
        'estimated_departure_at',
    ];

    protected function casts(): array
    {
        return [
            'stall_number' => 'integer',
            'status' => EvStallStatus::class,
            'occupied_since' => 'datetime',
            'estimated_departure_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(EvChargingStation::class, 'station_id');
    }

    public function currentDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_vehicle_driver_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(EvReservation::class, 'stall_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', EvStallStatus::Available);
    }

    public function scopeOccupied(Builder $query): Builder
    {
        return $query->where('status', EvStallStatus::Occupied);
    }

    public function isAvailable(): bool
    {
        return $this->status === EvStallStatus::Available;
    }

    public function isDepartureImminent(): bool
    {
        if ($this->status !== EvStallStatus::Occupied || ! $this->estimated_departure_at) {
            return false;
        }

        $threshold = config('ev_charging.imminent_departure_minutes', 15);

        return $this->estimated_departure_at->diffInMinutes(now(), absolute: true) <= $threshold
            && $this->estimated_departure_at->isFuture();
    }
}
