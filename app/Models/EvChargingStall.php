<?php

namespace App\Models;

use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Observers\EvAvailabilityObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(EvAvailabilityObserver::class)]
class EvChargingStall extends Model
{
    // Removing a stall archives it: its reservations keep pointing at it,
    // and adding the same stall number again brings it back.
    use HasFactory, HasUuids, SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'station_id',
        'stall_number',
        'status',
        'current_vehicle_driver_id',
        'occupied_since',
        'estimated_departure_at',
        // No automatic timestamps: whoever changes the stall sets it.
        'updated_at',
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
        return $this->belongsTo(EvChargingStation::class, 'station_id')->withTrashed();
    }

    public function currentDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_vehicle_driver_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(EvReservation::class, 'stall_id');
    }

    /**
     * The reservation using the stall now: held for a driver on their way
     * (reserved) or the driver charging at it (active). A stall has at most
     * one; latestOfMany() would break on uuid keys (Postgres has no
     * max(uuid)), so it is a plain ordered relation.
     */
    public function openReservation(): HasOne
    {
        return $this->hasOne(EvReservation::class, 'stall_id')
            ->whereIn('status', [EvReservationStatus::Reserved, EvReservationStatus::Active])
            ->latest('reserved_at');
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

        $threshold = AppSetting::evCharging()['imminent_departure_minutes'];

        return $this->estimated_departure_at->diffInMinutes(now(), absolute: true) <= $threshold
            && $this->estimated_departure_at->isFuture();
    }
}
