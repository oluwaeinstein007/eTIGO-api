<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * When and where a driver expects to drive (OQ-20). A forecast for
 * operations only: never enforced and never shown to passengers.
 */
class DriverAvailabilityPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'driver_id',
        'city_id',
        'area_label',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function scopeUpcoming(Builder $query): void
    {
        $query->where('ends_at', '>', now());
    }

    public function scopeOverlapping(Builder $query, Carbon $startsAt, Carbon $endsAt): void
    {
        $query->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt);
    }
}
