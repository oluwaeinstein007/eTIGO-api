<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'ride_id',
        'amount',
        'currency',
        'method',
        'gateway_transaction_id',
        'gateway_payment_method_id',
        'tip_amount',
        'status',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tip_amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(UserPaymentMethod::class, 'gateway_payment_method_id');
    }

    public function isCaptured(): bool
    {
        return $this->status === PaymentStatus::Captured;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isPendingCollection(): bool
    {
        return $this->status === PaymentStatus::PendingCollection;
    }

    public function isCollected(): bool
    {
        return $this->status === PaymentStatus::Collected;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [
            PaymentStatus::Captured,
            PaymentStatus::Collected,
            PaymentStatus::Settled,
        ]);
    }
}
