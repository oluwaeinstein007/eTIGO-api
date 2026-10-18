<?php

namespace App\Models;

use App\Enums\DisputeEvidenceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file sent as evidence on a dispute. Visible to the reporter, the
 * assigned reviewer, higher tiers and Safety only (NF-12).
 */
class DisputeEvidence extends Model
{
    use HasUuids;

    protected $table = 'dispute_evidence';

    protected $fillable = [
        'dispute_id',
        'uploaded_by_user_id',
        'type',
        'file_path',
        'file_type',
        'file_size_bytes',
        'original_name',
        'caption',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DisputeEvidenceType::class,
            'file_size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
