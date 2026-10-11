<?php

namespace App\Models;

use App\Enums\EvReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvReservation extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'stall_id',
        'station_id',
        'driver_id',
        'status',
        'queue_position',
        'estimated_available_at',
        'fee_amount',
        'fee_waived',
        'reserved_at',
        'activated_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EvReservationStatus::class,
            'queue_position' => 'integer',
            'fee_amount' => 'decimal:2',
            'fee_waived' => 'boolean',
            'estimated_available_at' => 'datetime',
            'reserved_at' => 'datetime',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function stall(): BelongsTo
    {
        return $this->belongsTo(EvChargingStall::class, 'stall_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(EvChargingStation::class, 'station_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            EvReservationStatus::Reserved,
            EvReservationStatus::Queued,
            EvReservationStatus::Active,
        ]);
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', EvReservationStatus::Queued)
            ->orderBy('queue_position');
    }

    public function scopeForDriver(Builder $query, string $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    public function scopeForStation(Builder $query, string $stationId): Builder
    {
        return $query->where('station_id', $stationId);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
