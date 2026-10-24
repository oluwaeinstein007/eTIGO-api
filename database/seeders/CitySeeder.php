<?php

namespace Database\Seeders;

use App\Enums\CityLaunchStatus;
use App\Models\City;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\AppCacheService;
use App\Services\CityBoundaryService;
use Database\Seeders\Data\NigerianCities;
use Illuminate\Database\Seeder;

/**
 * Cities across Nigeria (Data\NigerianCities), all live. Each has a map
 * boundary around its built-up area and the vehicle classes its fare tier
 * offers.
 *
 * Only missing cities are created, so launch statuses and boundaries changed
 * by admins are kept. A city still on the circle an earlier seeder drew gets
 * its outline. A boundary that would overlap an existing city is not drawn,
 * since each pickup must map to one city.
 */
class CitySeeder extends Seeder
{
    /**
     * Vehicle classes offered per fare tier.
     */
    private const CLASSES_BY_TIER = [
        'A' => ['economy', 'comfort', 'premium', 'suv'],
        'B' => ['economy', 'comfort', 'premium'],
        'C' => ['economy', 'comfort'],
    ];

    public function run(CityBoundaryService $boundaries): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping CitySeeder in production.');

            return;
        }

        $this->seedVehicleClasses();

        $admin = User::where('email', 'admin@etigo.com')->first();
        $classes = VehicleClass::all()->keyBy('name');

        foreach (NigerianCities::all() as $data) {
            $boundary = ['type' => 'Polygon', 'coordinates' => $data['boundary']];
            $city = City::where('slug', $data['slug'])->first();

            if ($city === null) {
                $city = $this->createCity($data, $this->drawable($boundaries, $boundary, $data['name']), $admin);
            } elseif ($this->hasLegacyBoundary($city, $data['slug'])
                && ($drawn = $this->drawable($boundaries, $boundary, $data['name'], $city->id)) !== null) {
                $city->update([
                    'boundary' => $drawn,
                    'area_sq_km' => City::calculateAreaFromPolygon($drawn['coordinates']),
                ]);
            }

            if (! $city->vehicleClasses()->exists()) {
                $offered = collect(self::CLASSES_BY_TIER[$data['tier']])
                    ->filter(fn (string $name) => $classes->has($name))
                    ->values();

                $city->vehicleClasses()->sync($offered->mapWithKeys(fn (string $name, int $i) => [
                    $classes[$name]->id => ['is_active' => true, 'sort_order' => $i + 1],
                ])->all());
            }
        }

        app(AppCacheService::class)->invalidateCities();
    }

    /**
     * Whether the city still has the circle an earlier seeder drew. Loose
     * comparison, as JSON turns 40.0 into 40; a city with no legacy circle
     * (or a boundary an admin cleared) never matches.
     */
    private function hasLegacyBoundary(City $city, string $slug): bool
    {
        $legacy = NigerianCities::legacyBoundaries()[$slug] ?? null;

        return $legacy !== null && is_array($city->boundary) && $city->boundary == $legacy;
    }

    private function seedVehicleClasses(): void
    {
        $classes = [
            ['name' => 'economy', 'display_name' => 'Economy', 'capacity' => 4, 'description' => 'Affordable rides for everyday trips.'],
            ['name' => 'comfort', 'display_name' => 'Comfort', 'capacity' => 4, 'description' => 'Newer cars with more legroom and air conditioning.'],
            ['name' => 'premium', 'display_name' => 'Premium', 'capacity' => 4, 'description' => 'Executive cars and top-rated drivers.'],
            ['name' => 'suv', 'display_name' => 'SUV', 'capacity' => 6, 'description' => 'Spacious SUVs and minivans for groups and luggage.'],
        ];

        foreach ($classes as $class) {
            VehicleClass::firstOrCreate(['name' => $class['name']], $class + ['is_active' => true]);
        }
    }

    /**
     * The boundary, or null (with a warning) when it would overlap a city
     * already saved.
     *
     * @param  array<string, mixed>  $boundary
     * @return array<string, mixed>|null
     */
    private function drawable(CityBoundaryService $boundaries, array $boundary, string $name, ?string $exceptCityId = null): ?array
    {
        $overlap = $boundaries->findOverlappingCity($boundary, $exceptCityId);

        if ($overlap !== null) {
            $this->command?->warn("{$name}: boundary not drawn, it would overlap {$overlap->name}.");

            return null;
        }

        return $boundary;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $boundary
     */
    private function createCity(array $data, ?array $boundary, ?User $admin): City
    {
        $status = CityLaunchStatus::from($data['launch_status']);

        // Launched cities went live in tier order: Lagos and Abuja first.
        $launchedAt = now()->subDays(match ($data['tier']) {
            'A' => 60,
            'B' => 30,
            default => 14,
        })->setTime(8, 0);

        $confirmed = fn (?string $value = null) => [
            'confirmed_at' => $launchedAt->copy()->subDays(3)->toIso8601String(),
            'confirmed_by_admin_id' => $admin?->id,
            'note' => null,
            'value' => $value,
        ];

        $hasLaunched = $status !== CityLaunchStatus::Onboarding;

        return City::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'state' => $data['state'],
            'region' => $data['region'],
            'timezone' => 'Africa/Lagos',
            'currency_code' => 'NGN',
            'boundary' => $boundary,
            'area_sq_km' => $boundary ? City::calculateAreaFromPolygon($boundary['coordinates']) : null,
            'launch_status' => $status,
            'launch_date' => $hasLaunched ? $launchedAt->toDateString() : $data['launch_date'],
            'launched_at' => $hasLaunched ? $launchedAt : null,
            'paused_at' => $status === CityLaunchStatus::Paused ? now()->subDays(5) : null,
            'pause_reason' => $status === CityLaunchStatus::Paused ? 'Too few active drivers while the city team is rebuilt.' : null,
            'pause_scheduled_rides' => $status === CityLaunchStatus::Paused ? 'honour' : null,
            'readiness_confirmations' => $hasLaunched
                ? ['support_cover' => $confirmed(), 'permits' => $confirmed('obtained')]
                : null,
        ]);
    }
}
