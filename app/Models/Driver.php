<?php

namespace App\Models;

use App\Enums\DriverStatus;
use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Driver extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'city_id',
        'status',
        'kyc_status',
        'kyc_verified_at',
        'licence_number',
        'rejection_reason',
        'is_online',
        'approved_at',
        'suspended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'kyc_status' => KycStatus::class,
            'is_online' => 'boolean',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'kyc_verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class);
    }

    public function kycVerifications(): HasMany
    {
        return $this->hasMany(KycVerification::class);
    }

    public function isApproved(): bool
    {
        return $this->status === DriverStatus::Approved;
    }

    public function isPendingReview(): bool
    {
        return $this->status === DriverStatus::PendingReview;
    }

    public function isSuspended(): bool
    {
        return $this->status === DriverStatus::Suspended;
    }

    public function isKycVerified(): bool
    {
        return $this->kyc_status === KycStatus::Verified;
    }

    public function canGoOnline(): bool
    {
        return $this->isApproved()
            && $this->vehicle !== null
            && $this->vehicle->vehicle_class_id !== null;
    }
}
