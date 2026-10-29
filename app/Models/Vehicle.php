<?php

namespace App\Models;

use App\Enums\FuelType;
use App\Services\VehicleMaintenanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory, HasUuids;

    public const FLEET_STATUS_AVAILABLE = 'available';

    public const FLEET_STATUS_ASSIGNED = 'assigned';

    public const FLEET_STATUS_MAINTENANCE = 'maintenance';

    public const FLEET_STATUS_BLOCKED = 'blocked';

    public const FLEET_STATUSES = [
        self::FLEET_STATUS_AVAILABLE,
        self::FLEET_STATUS_ASSIGNED,
        self::FLEET_STATUS_MAINTENANCE,
        self::FLEET_STATUS_BLOCKED,
    ];

    protected $fillable = [
        'driver_id',
        'make',
        'model',
        'colour',
        'plate_number',
        'year',
        'fuel_type',
        'is_fleet',
        'shift_mode',
        'vehicle_class_id',
        'odometer_km',
        'odometer_read_at',
        'trip_km_since_odometer',
        'battery_level_percent',
        'battery_level_at',
        'blocked_at',
        'block_reason',
        'maintenance_started_at',
        'maintenance_reason',
    ];

    protected static function booted(): void
    {
        // Maintenance items follow the fuel type: battery health is added for
        // plug-in cars and archived when the fuel type is corrected away.
        // A new car has no papers or records yet, so its items start as
        // "no record"; they are worked out on the car's first check.
        static::created(fn (Vehicle $vehicle) => app(VehicleMaintenanceService::class)->ensureItems($vehicle));
        static::updated(function (Vehicle $vehicle) {
            if ($vehicle->wasChanged('fuel_type')) {
                app(VehicleMaintenanceService::class)->syncItems($vehicle);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'fuel_type' => FuelType::class,
            'is_fleet' => 'boolean',
            'shift_mode' => 'boolean',
            'odometer_km' => 'integer',
            'odometer_read_at' => 'datetime',
            'trip_km_since_odometer' => 'decimal:2',
            'battery_level_percent' => 'integer',
            'battery_level_at' => 'datetime',
            'blocked_at' => 'datetime',
            'maintenance_started_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function fleetAgreements(): HasMany
    {
        return $this->hasMany(FleetAgreement::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(DriverShift::class);
    }

    public function maintenanceItems(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceItem::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceRecord::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    /**
     * Expired papers keep the car off the road (after the grace period).
     */
    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * A fleet car E-tiGo has taken off the road for servicing.
     */
    public function isInMaintenance(): bool
    {
        return $this->maintenance_started_at !== null;
    }

    /**
     * Where a fleet car stands, most pressing first: papers expired past the
     * grace period (blocked), in for servicing, with a driver, or free.
     */
    public function fleetStatus(): string
    {
        return match (true) {
            $this->isBlocked() => self::FLEET_STATUS_BLOCKED,
            $this->isInMaintenance() => self::FLEET_STATUS_MAINTENANCE,
            $this->isAssigned() => self::FLEET_STATUS_ASSIGNED,
            default => self::FLEET_STATUS_AVAILABLE,
        };
    }

    /**
     * Fleet cars with the given fleetStatus(), with the same precedence.
     */
    public function scopeWithFleetStatus(Builder $query, string $status): void
    {
        match ($status) {
            self::FLEET_STATUS_BLOCKED => $query->whereNotNull('blocked_at'),
            self::FLEET_STATUS_MAINTENANCE => $query->whereNull('blocked_at')->whereNotNull('maintenance_started_at'),
            self::FLEET_STATUS_ASSIGNED => $query->whereNull('blocked_at')->whereNull('maintenance_started_at')->whereNotNull('driver_id'),
            default => $query->whereNull('blocked_at')->whereNull('maintenance_started_at')->whereNull('driver_id'),
        };
    }

    /**
     * Odometer estimate: the last reading plus the trip distance E-tiGo has
     * recorded since. Driving off the platform is not counted, which is why
     * the time limits still apply.
     */
    public function estimatedOdometerKm(): ?int
    {
        if ($this->odometer_km === null) {
            return null;
        }

        return (int) round($this->odometer_km + (float) $this->trip_km_since_odometer);
    }

    public function isAssigned(): bool
    {
        return $this->driver_id !== null;
    }

    public function scopeFleetInventory(Builder $query): void
    {
        $query->where('is_fleet', true)->whereNull('driver_id');
    }

    public function scopeFleet(Builder $query): void
    {
        $query->where('is_fleet', true);
    }

    public function scopeNotBlocked(Builder $query): void
    {
        $query->whereNull('blocked_at');
    }
}
