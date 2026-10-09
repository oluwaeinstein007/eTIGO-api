<?php

namespace App\Models;

use App\Enums\FleetAgreementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetAgreement extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'daily_remittance_target',
        'total_vehicle_cost',
        'total_remitted',
        'agreement_start_date',
        'status',
        'shortfall_streak_days',
        'terminated_reason',
        'terminated_at',
        'completed_at',
        'paused_at',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => FleetAgreementStatus::class,
            'daily_remittance_target' => 'decimal:2',
            'total_vehicle_cost' => 'decimal:2',
            'total_remitted' => 'decimal:2',
            'agreement_start_date' => 'date',
            'shortfall_streak_days' => 'integer',
            'terminated_at' => 'datetime',
            'completed_at' => 'datetime',
            'paused_at' => 'datetime',
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

    public function dailyRemittances(): HasMany
    {
        return $this->hasMany(DailyRemittance::class, 'agreement_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', FleetAgreementStatus::Active);
    }

    public function scopeForDriver(Builder $query, string $driverId): void
    {
        $query->where('driver_id', $driverId);
    }

    public function isActive(): bool
    {
        return $this->status === FleetAgreementStatus::Active;
    }

    public function isCompleted(): bool
    {
        return $this->status === FleetAgreementStatus::Completed;
    }

    public function isTerminated(): bool
    {
        return $this->status === FleetAgreementStatus::Terminated;
    }

    public function progressPercentage(): float
    {
        if ($this->total_vehicle_cost <= 0) {
            return 0;
        }

        return min(100, round(($this->total_remitted / $this->total_vehicle_cost) * 100, 2));
    }

    public function remainingAmount(): float
    {
        return max(0, $this->total_vehicle_cost - $this->total_remitted);
    }
}
