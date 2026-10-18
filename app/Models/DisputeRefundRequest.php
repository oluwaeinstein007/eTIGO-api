<?php

namespace App\Models;

use App\Enums\DisputeRefundMethod;
use App\Enums\DisputeRefundRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dispute decision whose refund waits for a second admin: above the
 * requester's tier limit, or back to the card (Finance). Nobody approves
 * their own request.
 */
class DisputeRefundRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'dispute_id',
        'requested_by_admin_id',
        'amount',
        'currency',
        'method',
        'clawback_driver_earnings',
        'resolution_notes',
        'status',
        'reviewed_by_admin_id',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => DisputeRefundMethod::class,
            'status' => DisputeRefundRequestStatus::class,
            'clawback_driver_earnings' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_admin_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_admin_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DisputeRefundRequestStatus::Pending);
    }

    public function isPending(): bool
    {
        return $this->status === DisputeRefundRequestStatus::Pending;
    }
}
