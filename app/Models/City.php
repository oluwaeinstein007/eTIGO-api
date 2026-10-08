<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    ];

    protected function casts(): array
    {
        return [
            'boundary' => 'array',
            'area_sq_km' => 'decimal:2',
            'is_active' => 'boolean',
        ];
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
}
