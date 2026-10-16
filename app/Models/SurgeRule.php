<?php

namespace App\Models;

use App\Enums\SurgeType;
use Carbon\CarbonInterface;
use Database\Factories\SurgeRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurgeRule extends Model
{
    /** @use HasFactory<SurgeRuleFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'city_id',
        'vehicle_class_id',
        'name',
        'type',
        'multiplier',
        'conditions',
        'priority',
        'is_active',
        'effective_from',
        'effective_until',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => SurgeType::class,
            'multiplier' => 'decimal:2',
            'conditions' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    /**
     * Rules switched on and in effect at the given moment (now by default;
     * a scheduled ride's quote passes its pickup time).
     */
    public function scopeActive($query, ?CarbonInterface $at = null)
    {
        $at ??= now();

        return $query->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $at);
            });
    }

    public function scopeForCity($query, string $cityId)
    {
        return $query->where('city_id', $cityId);
    }

    public function scopeForVehicleClass($query, ?string $vehicleClassId)
    {
        return $query->where(function ($q) use ($vehicleClassId) {
            $q->whereNull('vehicle_class_id');

            if ($vehicleClassId) {
                $q->orWhere('vehicle_class_id', $vehicleClassId);
            }
        });
    }
}
