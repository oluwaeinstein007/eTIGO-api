<?php

namespace App\Models;

use App\Enums\MaintenanceItemType;
use App\Enums\MaintenanceRecordStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Work done on a vehicle: recorded by operations (fleet cars, approved at
 * once) or submitted by the driver with proof (own cars, approved by
 * operations).
 */
class VehicleMaintenanceRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'vehicle_id',
        'item_type',
        'performed_at',
        'odometer_km',
        'workshop',
        'notes',
        'proof_path',
        'proof_mime_type',
        'driver_repair_id',
        'status',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => MaintenanceItemType::class,
            'status' => MaintenanceRecordStatus::class,
            'performed_at' => 'date',
            'odometer_km' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(DriverRepair::class, 'driver_repair_id');
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('status', MaintenanceRecordStatus::Approved);
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', MaintenanceRecordStatus::Pending);
    }
}
