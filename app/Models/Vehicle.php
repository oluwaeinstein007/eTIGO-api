<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'make',
        'model',
        'colour',
        'plate_number',
        'year',
        'vehicle_class',
        'vehicle_class_id',
        'vehicle_class_approved',
        'class_approved_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'vehicle_class_approved' => 'boolean',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function classApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'class_approved_by');
    }
}
