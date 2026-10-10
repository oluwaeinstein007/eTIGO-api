<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'driver_id',
        'bank_account_id',
        'amount',
        'status',
        'gateway_transfer_id',
        'gateway_reference',
        'failure_reason',
        'requested_at',
        'approved_at',
        'approved_by_admin_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount' => 'integer',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_id');
    }

    // ── Scopes ──

    public function scopePending($query)
    {
        return $query->where('status', PayoutStatus::Requested);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', PayoutStatus::Failed);
    }

    // ── Helpers ──

    public function isPending(): bool
    {
        return $this->status === PayoutStatus::Requested;
    }

    public function isApproved(): bool
    {
        return $this->status === PayoutStatus::Approved;
    }

    public function isPaid(): bool
    {
        return $this->status === PayoutStatus::Paid;
    }

    public function isFailed(): bool
    {
        return $this->status === PayoutStatus::Failed;
    }
}
