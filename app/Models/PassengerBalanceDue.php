<?php

namespace App\Models;

use App\Enums\BalanceDueReason;
use App\Enums\BalanceDueStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money a passenger owes that could not be collected at the time (a late
 * cancellation fee or a wallet fare shortfall).
 */
class PassengerBalanceDue extends Model
{
    use HasUuids;

    protected $table = 'passenger_balances_due';

    protected $fillable = [
        'passenger_id',
        'ride_id',
        'reason',
        'amount',
        'currency',
        'payee_driver_id',
        'status',
        'paid_at',
        'paid_via',
        'payment_reference',
        'waived_at',
        'waived_by_admin_id',
        'waive_reason',
    ];

    protected function casts(): array
    {
        return [
            'reason' => BalanceDueReason::class,
            'status' => BalanceDueStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'waived_at' => 'datetime',
        ];
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function payeeDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_driver_id');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', BalanceDueStatus::Outstanding);
    }

    public function amountKobo(): int
    {
        return (int) round((float) $this->amount * 100);
    }
}
