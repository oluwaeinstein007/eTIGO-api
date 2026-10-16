<?php

namespace App\Models;

use App\Enums\DisputeOutcome;
use App\Enums\DocumentStatus;
use App\Enums\DriverStatus;
use App\Enums\FleetAgreementStatus;
use App\Enums\KycStatus;
use App\Enums\PayoutPreference;
use App\Enums\VehicleOwnershipType;
use App\Traits\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Driver extends Model
{
    use HasFactory, HasReferenceNumber, HasUuids;

    protected $fillable = [
        'user_id',
        'city_id',
        'vehicle_ownership_type',
        'status',
        'kyc_status',
        'kyc_verified_at',
        'licence_number',
        'rejection_reason',
        'is_online',
        'payout_preference',
        'approved_at',
        'suspended_at',
        'suspension_reason',
        'reference_number',
    ];

    protected static function referencePrefix(): string
    {
        return 'D-';
    }

    protected static function booted(): void
    {
        // When the driver last went online, for the silent-driver check.
        static::saving(function (Driver $driver) {
            if ($driver->isDirty('is_online')) {
                $driver->online_since = $driver->is_online ? now() : null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'kyc_status' => KycStatus::class,
            'vehicle_ownership_type' => VehicleOwnershipType::class,
            'payout_preference' => PayoutPreference::class,
            'is_online' => 'boolean',
            'online_since' => 'datetime',
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

    public function fleetAgreements(): HasMany
    {
        return $this->hasMany(FleetAgreement::class);
    }

    public function activeFleetAgreement(): HasOne
    {
        return $this->hasOne(FleetAgreement::class)->where('status', FleetAgreementStatus::Active);
    }

    public function dailyRemittances(): HasMany
    {
        return $this->hasMany(DailyRemittance::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function primaryBankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class)->where('is_primary', true);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function offlineTripFlags(): HasMany
    {
        return $this->hasMany(OfflineTripFlag::class, 'driver_id', 'user_id');
    }

    public function activeOfflineFlags(): HasMany
    {
        return $this->offlineTripFlags()
            ->where(function ($q) {
                $q->where('is_disputed', false)
                    ->orWhere('dispute_outcome', '!=', DisputeOutcome::Overturned);
            })
            ->whereNull('resolved_at');
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

    public function isDeactivated(): bool
    {
        return $this->status === DriverStatus::Deactivated;
    }

    public function isKycVerified(): bool
    {
        return $this->kyc_status === KycStatus::Verified;
    }

    public function isOwnVehicle(): bool
    {
        return $this->vehicle_ownership_type === VehicleOwnershipType::OwnVehicle;
    }

    public function isFleetVehicle(): bool
    {
        return $this->vehicle_ownership_type === VehicleOwnershipType::FleetVehicle;
    }

    public function isAwaitingVehicleAssignment(): bool
    {
        return $this->isFleetVehicle()
            && $this->isApproved()
            && $this->vehicle === null;
    }

    public function isProfileComplete(): bool
    {
        if ($this->user === null || $this->city_id === null || $this->vehicle === null || ! $this->isKycVerified()) {
            return false;
        }

        $hasBankAccount = $this->relationLoaded('primaryBankAccount')
            ? $this->primaryBankAccount !== null
            : ($this->relationLoaded('bankAccounts')
                ? $this->bankAccounts->isNotEmpty()
                : $this->bankAccounts()->exists());

        if (! $hasBankAccount) {
            return false;
        }

        $requiredDocTypes = ['driving_licence', 'government_id'];
        if ($this->isOwnVehicle()) {
            $requiredDocTypes[] = 'vehicle_registration';
            $requiredDocTypes[] = 'insurance_certificate';
        }

        $docs = $this->relationLoaded('documents')
            ? $this->documents
            : $this->documents()->get();

        $uploadedTypes = $docs
            ->filter(fn ($d) => ($d->status ?? null) !== DocumentStatus::Rejected)
            ->pluck('type')
            ->map(fn ($t) => is_object($t) ? $t->value : (string) $t)
            ->toArray();

        return empty(array_diff($requiredDocTypes, $uploadedTypes));
    }

    public function canGoOnline(): bool
    {
        return $this->isApproved()
            && $this->isKycVerified()
            && $this->vehicle !== null
            && $this->vehicle->vehicle_class_id !== null;
    }

    public function scopeAwaitingVehicle(Builder $query): void
    {
        $query->where('vehicle_ownership_type', VehicleOwnershipType::FleetVehicle)
            ->where('status', DriverStatus::Approved)
            ->whereDoesntHave('vehicle');
    }
}
