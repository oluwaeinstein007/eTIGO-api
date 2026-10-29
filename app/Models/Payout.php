<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use App\Traits\HasReferenceNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    use HasFactory, HasReferenceNumber, HasUuids;

    protected $fillable = [
        'reference_number',
        'driver_id',
        'bank_account_id',
        'amount',
        'status',
        'gateway_transfer_id',
        'gateway_reference',
        'gateway_recipient_code',
        'provider_status',
        'failure_reason',
        'requested_at',
        'approved_at',
        'approved_by_admin_id',
        'rejected_at',
        'rejected_by_admin_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount' => 'integer',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_admin_id');
    }

    protected static function referencePrefix(): string
    {
        return 'PO-';
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

    /** Paystack holds a transfer in this status until someone enters an OTP. */
    public const PROVIDER_AWAITING_OTP = 'otp';

    /** An approved payout is normally sent within seconds; after this it is stuck. */
    public const APPROVED_STALE_MINUTES = 10;

    /** Transfers usually settle within minutes; after this the webhook is presumed missing. */
    public const PROCESSING_STALE_MINUTES = 30;

    /**
     * Approved but not sent, or sent with no result from the provider, for
     * longer than a transfer normally takes.
     */
    public function isAwaitingConfirmation(): bool
    {
        return $this->attentionReason() !== null;
    }

    /**
     * Why finance should look at this payout: Paystack is holding the
     * transfer for an OTP, it was never sent, or it was never confirmed.
     */
    public function attentionReason(): ?string
    {
        if ($this->status === PayoutStatus::Processing && $this->provider_status === self::PROVIDER_AWAITING_OTP) {
            return 'awaiting_otp';
        }

        return match ($this->status) {
            PayoutStatus::Approved => $this->approved_at?->lte(now()->subMinutes(self::APPROVED_STALE_MINUTES)) ? 'not_sent' : null,
            PayoutStatus::Processing => $this->updated_at?->lte(now()->subMinutes(self::PROCESSING_STALE_MINUTES)) ? 'unconfirmed' : null,
            default => null,
        };
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
