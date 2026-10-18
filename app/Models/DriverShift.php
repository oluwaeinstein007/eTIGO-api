<?php

namespace App\Models;

use App\Enums\DriverShiftStatus;
use Database\Factories\DriverShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A driver's turn with a shared fleet car (vehicles.shift_mode). Times are
 * full date-times, so a shift can run past midnight.
 */
class DriverShift extends Model
{
    /** @use HasFactory<DriverShiftFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'starts_at',
        'ends_at',
        'status',
        'started_at',
        'ended_at',
        'missed_at',
        'cancelled_at',
        'cancellation_reason',
        'handover_delay_notified_at',
        'end_warning_sent_at',
        'notes',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DriverShiftStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'missed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'handover_delay_notified_at' => 'datetime',
            'end_warning_sent_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    /**
     * Shifts that still occupy their time slot (everything but cancelled).
     */
    public function scopeHoldingSlot(Builder $query): void
    {
        $query->whereIn('status', DriverShiftStatus::holdingSlot());
    }

    /**
     * Shifts the driver can still go online for.
     */
    public function scopeWorkable(Builder $query): void
    {
        $query->whereIn('status', DriverShiftStatus::workable());
    }

    public function scopeOverlapping(Builder $query, Carbon $startsAt, Carbon $endsAt): void
    {
        $query->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt);
    }

    /**
     * Shifts the driver may be online for at $at: from the online lead
     * before the start until the end.
     */
    public function scopeOpenAt(Builder $query, Carbon $at, int $leadMinutes): void
    {
        $query->workable()
            ->where('starts_at', '<=', $at->copy()->addMinutes($leadMinutes))
            ->where('ends_at', '>', $at);
    }

    public function onlineFrom(int $leadMinutes): Carbon
    {
        return $this->starts_at->copy()->subMinutes($leadMinutes);
    }

    public function offersStopAt(int $cutoffMinutes): Carbon
    {
        return $this->ends_at->copy()->subMinutes($cutoffMinutes);
    }

    public function isWorkable(): bool
    {
        return in_array($this->status, DriverShiftStatus::workable(), true);
    }
}
