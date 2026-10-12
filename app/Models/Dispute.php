<?php

namespace App\Models;

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Traits\HasReferenceNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dispute extends Model
{
    use HasFactory, HasReferenceNumber, HasUuids;

    protected $fillable = [
        'ride_id',
        'reported_by_user_id',
        'category',
        'description',
        'evidence_photos',
        'status',
        'resolution_notes',
        'refund_amount',
        'refund_currency',
        'resolved_by_admin_id',
        'resolved_at',
        'reference_number',
    ];

    protected static function referencePrefix(): string
    {
        return 'DPT-';
    }

    protected function casts(): array
    {
        return [
            'category' => DisputeCategory::class,
            'status' => DisputeStatus::class,
            'evidence_photos' => 'array',
            'refund_amount' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_admin_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(DisputeNote::class);
    }
}
