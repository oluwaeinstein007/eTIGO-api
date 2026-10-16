<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Scheduling details of a ride booked ahead of time. The ride itself carries
 * the state (`scheduled` until dispatch, then the normal ride flow); this row
 * records when the pickup is and what the dispatcher has done.
 */
class ScheduledRide extends Model
{
    use HasUuids;

    protected $fillable = [
        'ride_id',
        'scheduled_pickup_at',
        'reminder_sent_at',
        'dispatched_at',
        'cancelled_at',
        'dispatch_failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_pickup_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    /**
     * Bookings still waiting for their dispatch time.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereNull('dispatched_at')->whereNull('cancelled_at');
    }

    public function isUpcoming(): bool
    {
        return $this->dispatched_at === null && $this->cancelled_at === null;
    }

    /**
     * When matching starts for this booking under the given lead time.
     */
    public function dispatchAt(int $leadMinutes): Carbon
    {
        return $this->scheduled_pickup_at->copy()->subMinutes($leadMinutes);
    }
}
