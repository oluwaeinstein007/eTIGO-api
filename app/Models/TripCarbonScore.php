<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripCarbonScore extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'ride_id',
        'user_id',
        'distance_km',
        'baseline_emission',
        'vehicle_emission',
        'co2_saved',
        'base_points',
        'multiplier_applied',
        'multiplier_reason',
        'final_points',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:2',
            'baseline_emission' => 'decimal:4',
            'vehicle_emission' => 'decimal:4',
            'co2_saved' => 'decimal:4',
            'base_points' => 'integer',
            'multiplier_applied' => 'decimal:2',
            'final_points' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
