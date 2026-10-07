<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'driver_id',
        'make',
        'model',
        'colour',
        'plate_number',
        'year',
        'is_fleet',
        'vehicle_class_id',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'is_fleet' => 'boolean',
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
}
