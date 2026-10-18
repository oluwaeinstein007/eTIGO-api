<?php

namespace App\Models;

use App\Enums\DisputeOutcome;
use App\Enums\DocumentStatus;
use App\Enums\DriverStatus;
use App\Enums\FleetAgreementStatus;
use App\Enums\KycStatus;
use App\Enums\PayoutPreference;
use App\Enums\VehicleOwnershipType;
use App\Services\DriverShiftService;
use App\Services\ScheduledRideReservationService;
use App\Traits\HasReferenceNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // A driver who can no longer drive (suspended by operations or by an
        // automatic sanction, deactivated, rejected) gives up their reserved
        // scheduled pickups and fleet shifts at once, so passengers and
        // operations are not left waiting for pickup time to find out.
        static::updated(function (Driver $driver) {
            if (! $driver->wasChanged('status') || $driver->getOriginal('status') !== DriverStatus::Approved || $driver->status === DriverStatus::Approved) {
                return;
            }

            $reason = 'driver '.strtolower($driver->status->name);

            DB::afterCommit(function () use ($driver, $reason) {
                try {
                    app(ScheduledRideReservationService::class)->releaseAllFor($driver->user_id, 'driver_suspended');
                    app(DriverShiftService::class)->withdrawDriver($driver, $reason);
                } catch (\Throwable $e) {
                    Log::error('Could not release the reservations and shifts of a driver who can no longer drive', ['driver_id' => $driver->id, 'error' => $e->getMessage()]);
                }
            });
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

    public function shifts(): HasMany
    {
        return $this->hasMany(DriverShift::class);
    }

    public function availabilityPlans(): HasMany
    {
        return $this->hasMany(DriverAvailabilityPlan::class);
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
            && $this->vehicle === null
            && ! $this->shifts()->workable()->where('ends_at', '>', now())->exists();
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

    /**
     * Drivers new ride offers may go to, as far as their car allows: its
     * papers are not expired past the grace period, and a shared car's
     * driver is inside their shift (before the offer cut-off).
     */
    public function scopeDispatchable(Builder $query): void
    {
        $query->whereHas('vehicle', fn (Builder $v) => $v->whereNull('blocked_at'));
        app(DriverShiftService::class)->constrainDispatchable($query);
    }

    public function scopeAwaitingVehicle(Builder $query): void
    {
        $query->where('vehicle_ownership_type', VehicleOwnershipType::FleetVehicle)
            ->where('status', DriverStatus::Approved)
            ->whereDoesntHave('vehicle')
            // Drivers on a shared car's timetable have a car, just not right now.
            ->whereDoesntHave('shifts', fn (Builder $q) => $q->workable()->where('ends_at', '>', now()));
    }
}
