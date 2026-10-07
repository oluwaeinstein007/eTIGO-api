<?php

namespace App\Models;

use App\Enums\RideStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RideStateTransition extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'ride_id',
        'from_state',
        'to_state',
        'triggered_by_type',
        'triggered_by_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_state' => RideStatus::class,
            'to_state' => RideStatus::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_id');
    }
}
