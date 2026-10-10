<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyRemittance extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'agreement_id',
        'driver_id',
        'date',
        'target_amount',
        'remitted_amount',
        'shortfall_amount',
        'target_met_at',
        'ride_count',
        'total_fares',
        'driver_earnings',
        'settled',
        'excused_reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'target_amount' => 'decimal:2',
            'remitted_amount' => 'decimal:2',
            'shortfall_amount' => 'decimal:2',
            'total_fares' => 'decimal:2',
            'driver_earnings' => 'decimal:2',
            'target_met_at' => 'datetime',
            'ride_count' => 'integer',
            'settled' => 'boolean',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FleetAgreement::class, 'agreement_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function scopeForDriver(Builder $query, string $driverId): void
    {
        $query->where('driver_id', $driverId);
    }

    public function scopeUnsettled(Builder $query): void
    {
        $query->where('settled', false);
    }

    public function scopeForDate(Builder $query, string $date): void
    {
        $query->where('date', $date);
    }

    public function hasMetTarget(): bool
    {
        return $this->target_met_at !== null;
    }

    public function shortfall(): float
    {
        return max(0, $this->target_amount - $this->remitted_amount);
    }

    public function isExcused(): bool
    {
        return $this->excused_reason !== null;
    }

    public function scopeExcused(Builder $query): void
    {
        $query->whereNotNull('excused_reason');
    }

    public function scopeNotExcused(Builder $query): void
    {
        $query->whereNull('excused_reason');
    }
}
