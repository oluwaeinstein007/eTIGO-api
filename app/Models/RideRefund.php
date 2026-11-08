<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RideRefund extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'ride_id',
        'amount_kobo',
        'driver_clawback_kobo',
        'reason',
        'refunded_by_admin_id',
        'dispute_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'driver_clawback_kobo' => 'integer',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by_admin_id');
    }
}
