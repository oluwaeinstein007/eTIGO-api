<?php

namespace App\Models;

use App\Enums\CityLaunchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'state',
        'region',
        'boundary',
        'area_sq_km',
        'timezone',
        'currency_code',
        'is_active',
        'launch_status',
        'launch_date',
        'launched_at',
        'paused_at',
        'pause_reason',
        'pause_scheduled_rides',
        'launch_status_changed_at',
        'launch_status_changed_by_admin_id',
        'readiness_confirmations',
    ];

    protected static function booted(): void
    {
        // is_active is kept equal to "live" for the clients and queries that
        // still read it. A legacy write of is_active alone maps back to a
        // launch status: on for live; off for onboarding on a new city and
        // paused on an existing one.
        static::saving(function (City $city) {
            if ($city->isDirty('is_active') && ! $city->isDirty('launch_status')) {
                $city->launch_status = match (true) {
                    $city->is_active => CityLaunchStatus::Live,
                    $city->exists => CityLaunchStatus::Paused,
                    default => CityLaunchStatus::Onboarding,
                };
            }

            $city->launch_status ??= CityLaunchStatus::Onboarding;
            $city->is_active = $city->launch_status === CityLaunchStatus::Live;

            if ($city->is_active) {
                $city->launched_at ??= now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'boundary' => 'array',
            'area_sq_km' => 'decimal:2',
            'is_active' => 'boolean',
            'launch_status' => CityLaunchStatus::class,
            'launch_date' => 'date',
            'launched_at' => 'datetime',
            'paused_at' => 'datetime',
            'launch_status_changed_at' => 'datetime',
            'readiness_confirmations' => 'array',
        ];
    }

    public function isLive(): bool
    {
        return $this->launch_status === CityLaunchStatus::Live;
    }

    /**
     * Back in onboarding after having launched: closed, not new.
     */
    public function wasClosed(): bool
    {
        return $this->launch_status === CityLaunchStatus::Onboarding && $this->launched_at !== null;
    }

    /**
     * What a passenger sees when they try to book here.
     */
    public function unavailableMessage(): string
    {
        return $this->launch_status->unavailableMessage($this->name, $this->wasClosed());
    }

    public function acceptsBookings(): bool
    {
        return $this->launch_status?->acceptsBookings() ?? false;
    }

    /**
     * @param  Builder<City>  $query
     * @return Builder<City>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('launch_status', CityLaunchStatus::Live);
    }

    /**
     * Cities a driver can choose when signing up: onboarding and live.
     *
     * @param  Builder<City>  $query
     * @return Builder<City>
     */
    public function scopeOpenToDrivers(Builder $query): Builder
    {
        return $query->whereIn('launch_status', [CityLaunchStatus::Onboarding, CityLaunchStatus::Live]);
    }

    public static function calculateAreaFromPolygon(array $coordinates): float
    {
        $exteriorRing = $coordinates[0] ?? [];
        $area = self::calculateRingArea($exteriorRing);

        for ($i = 1, $ringCount = count($coordinates); $i < $ringCount; $i++) {
            $area -= self::calculateRingArea($coordinates[$i]);
        }

        return round(max($area, 0), 2);
    }

    private static function calculateRingArea(array $points): float
    {
        if (count($points) < 3) {
            return 0;
        }

        $earthRadiusKm = 6371;
        $sum = 0;
        $n = count($points);

        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $lng1 = deg2rad($points[$i][0]);
            $lat1 = deg2rad($points[$i][1]);
            $lng2 = deg2rad($points[$j][0]);
            $lat2 = deg2rad($points[$j][1]);

            $sum += ($lng2 - $lng1) * (2 + sin($lat1) + sin($lat2));
        }

        return abs($sum) * $earthRadiusKm * $earthRadiusKm / 2;
    }

    public static function calculateAreaFromPoint(float $radiusKm): float
    {
        return round(M_PI * $radiusKm * $radiusKm, 2);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function vehicleClasses(): BelongsToMany
    {
        return $this->belongsToMany(VehicleClass::class, 'city_vehicle_classes')
            ->withPivot('is_active', 'sort_order');
    }

    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class);
    }

    public function pricingConfigs(): HasMany
    {
        return $this->hasMany(PricingConfig::class);
    }

    public function evStations(): HasMany
    {
        return $this->hasMany(EvChargingStation::class);
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(CityWaitlistEntry::class);
    }

    /**
     * The admin who last changed the launch status.
     */
    public function launchStatusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'launch_status_changed_by_admin_id');
    }
}
