<?php

namespace App\Models;

use App\Enums\MaintenanceItemStatus;
use App\Enums\MaintenanceItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a vehicle is checked for, with its current due state. Kept up
 * to date by VehicleMaintenanceService; the history is in
 * vehicle_maintenance_records and the vehicle's documents.
 */
class VehicleMaintenanceItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'vehicle_id',
        'type',
        'status',
        'due_at',
        'due_odometer_km',
        'last_done_at',
        'last_odometer_km',
        'blocks_driving',
        'notes',
        'notified_status',
        'notified_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MaintenanceItemType::class,
            'status' => MaintenanceItemStatus::class,
            'notified_status' => MaintenanceItemStatus::class,
            'due_at' => 'date',
            'last_done_at' => 'date',
            'due_odometer_km' => 'integer',
            'last_odometer_km' => 'integer',
            'blocks_driving' => 'boolean',
            'notified_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
