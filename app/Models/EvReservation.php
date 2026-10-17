<?php

namespace App\Models;

use App\Enums\EvReservationStatus;
use App\Observers\EvAvailabilityObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(EvAvailabilityObserver::class)]
class EvReservation extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'stall_id',
        'station_id',
        'driver_id',
        'status',
        'type',
        'battery_level',
        'requested_date',
        'requested_time',
        'notes',
        'approver_name',
        'confirmed_slot',
        'check_in_time',
        'queue_position',
        'queue_ahead_count',
        'queue_ahead_description',
        'estimated_available_at',
        'fee_amount',
        'fee_waived',
        'fee_charged_at',
        'fee_refunded_at',
        'estimated_charge_minutes',
        'reserved_at',
        'activated_at',
        'completed_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EvReservationStatus::class,
            'battery_level' => 'integer',
            'queue_position' => 'integer',
            'queue_ahead_count' => 'integer',
            'fee_amount' => 'decimal:2',
            'fee_waived' => 'boolean',
            'fee_charged_at' => 'datetime',
            'fee_refunded_at' => 'datetime',
            'estimated_charge_minutes' => 'integer',
            'estimated_available_at' => 'datetime',
            'requested_date' => 'date',
            'reserved_at' => 'datetime',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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
            EvReservationStatus::PendingReview,
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
