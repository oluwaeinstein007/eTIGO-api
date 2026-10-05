<?php

namespace App\Models;

use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycVerification extends Model
{
    protected $fillable = [
        'driver_id',
        'type',
        'id_number',
        'provider_reference',
        'status',
        'provider_response',
        'match_data',
        'failure_reason',
        'verified_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => KycVerificationType::class,
            'status' => KycVerificationStatus::class,
            'provider_response' => 'encrypted:array',
            'match_data' => 'array',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function isVerified(): bool
    {
        return $this->status === KycVerificationStatus::Verified;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [
            KycVerificationStatus::Pending,
            KycVerificationStatus::Processing,
        ]);
    }

    public function scopeForType($query, KycVerificationType $type)
    {
        return $query->where('type', $type);
    }

    public function scopeLatestPerType($query)
    {
        return $query->orderByDesc('created_at');
    }
}
