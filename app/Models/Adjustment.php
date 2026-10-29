<?php

namespace App\Models;

use App\Enums\AdjustmentStatus;
use App\Enums\AdjustmentType;
use App\Traits\HasReferenceNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Adjustment extends Model
{
    use HasFactory, HasReferenceNumber, HasUuids;

    protected $fillable = [
        'reference_number',
        'account_id',
        'type',
        'amount',
        'reason',
        'status',
        'created_by_admin_id',
        'approved_by_admin_id',
        'journal_id',
        'approved_at',
        'rejected_by_admin_id',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'status' => AdjustmentStatus::class,
            'amount' => 'integer',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_admin_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    protected static function referencePrefix(): string
    {
        return 'AD-';
    }

    public function isPending(): bool
    {
        return $this->status === AdjustmentStatus::Pending;
    }
}
