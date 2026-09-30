<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ride extends Model
{
    use HasUuids;

    protected $fillable = [
        'city_id',
        'vehicle_class_id',
        'passenger_id',
        'driver_id',
        'pickup_lat',
        'pickup_lng',
        'pickup_address',
        'destination_lat',
        'destination_lng',
        'destination_address',
        'status',
        'pin_code',
        'share_token',
        'fare_estimate_amount',
        'final_fare_amount',
        'fare_currency',
        'pricing_snapshot',
        'payment_method',
        'payment_status',
        'cancelled_by',
        'cancellation_reason',
        'matched_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'pickup_lat' => 'decimal:7',
            'pickup_lng' => 'decimal:7',
            'destination_lat' => 'decimal:7',
            'destination_lng' => 'decimal:7',
            'fare_estimate_amount' => 'decimal:2',
            'final_fare_amount' => 'decimal:2',
            'pricing_snapshot' => 'array',
            'matched_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
