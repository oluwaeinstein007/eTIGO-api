<?php

namespace App\Services;

use App\Enums\CityReadinessGate;
use App\Enums\DriverStatus;
use App\Enums\FuelType;
use App\Enums\KycStatus;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\EvChargingStation;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * The city readiness checklist (Phase 2 multi-city, OQ-22). Computed gates
 * are read from the data each time; manual gates are confirmed by an admin
 * and stored on the city with who and when. A city's first launch needs
 * every required gate; resuming a paused city does not, since it has
 * already launched, but the report is shown so the admin can check.
 */
class CityReadinessService
{
    public const PERMIT_VALUES = ['obtained', 'not_required'];

    public function __construct(
        private CityBoundaryService $boundaries,
        private PricingCoverageService $coverage,
    ) {}

    /**
     * @return array{ready: bool, missing: list<string>, gates: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function report(City $city): array
    {
        $settings = AppSetting::cities();
        $facts = $this->facts(new Collection([$city]))[$city->id];
        $gates = $this->gates($city, $facts, $settings, withNames: true);
        $missing = $this->missing($gates);

        return [
            'ready' => $missing === [],
            'missing' => $missing,
            'gates' => $gates,
            'counts' => [
                'approved_drivers' => $facts['approved_drivers'],
                'min_approved_drivers' => $settings['launch_min_approved_drivers'],
                'waitlist' => $city->waitlistEntries()->whereNull('notified_at')->count(),
                'active_rides' => Ride::where('city_id', $city->id)
                    ->whereIn('status', RideStatus::activeStatuses())
                    ->count(),
                'upcoming_scheduled_rides' => $this->upcomingScheduledRides($city)->count(),
            ],
        ];
    }

    /**
     * Ready and missing for many cities at once, for the admin city list:
     * the same gates as report(), read in a few grouped queries rather than
     * a dozen per city.
     *
     * @param  Collection<int, City>  $cities
     * @param  array<string, list<City>>|null  $overlaps  CityBoundaryService::overlapsFor, when already read
     * @return array<string, array{ready: bool, missing: list<string>}>
     */
    public function summaries(Collection $cities, ?array $overlaps = null): array
    {
        if ($cities->isEmpty()) {
            return [];
        }

        $settings = AppSetting::cities();
        $facts = $this->facts($cities, $overlaps);

        return $cities->mapWithKeys(function (City $city) use ($facts, $settings) {
            $missing = $this->missing($this->gates($city, $facts[$city->id], $settings, withNames: false));

            return [$city->id => ['ready' => $missing === [], 'missing' => $missing]];
        })->all();
    }

    /**
     * Confirm a manual gate, or clear it with $confirmed false.
     */
    public function confirm(City $city, CityReadinessGate $gate, User $admin, bool $confirmed, ?string $note, ?string $value): City
    {
        return DB::transaction(function () use ($city, $gate, $admin, $confirmed, $note, $value) {
            $city = City::lockForUpdate()->findOrFail($city->id);
            $confirmations = $city->readiness_confirmations ?? [];
            $old = $confirmations[$gate->value] ?? null;

            if ($confirmed) {
                $confirmations[$gate->value] = [
                    'confirmed_at' => now()->toIso8601String(),
                    'confirmed_by_admin_id' => $admin->id,
                    'note' => $note,
                    'value' => $value,
                ];
            } else {
                unset($confirmations[$gate->value]);
            }

            $city->update(['readiness_confirmations' => $confirmations === [] ? null : $confirmations]);

            AuditLog::record(
                $city,
                $confirmed ? 'city_readiness_confirmed' : 'city_readiness_cleared',
                $admin,
                ['gate' => $gate->value, 'confirmation' => $old],
                ['gate' => $gate->value, 'confirmation' => $confirmations[$gate->value] ?? null],
            );

            return $city;
        });
    }

    /**
     * Upcoming, not yet dispatched bookings in the city.
     *
     * @return Builder<Ride>
     */
    public function upcomingScheduledRides(City $city): Builder
    {
        return Ride::where('city_id', $city->id)
            ->where('status', RideStatus::Scheduled)
            ->whereHas('scheduledRide', fn ($q) => $q->upcoming());
    }

    /**
     * What the computed gates read, for each city.
     *
     * @param  Collection<int, City>  $cities
     * @param  array<string, list<City>>|null  $overlaps
     * @return array<string, array{overlap: City|null, offered_classes: int, unpriced_classes: list<string>, approved_drivers: int, plug_in_drivers: int, active_stations: int, promo_codes: int}>
     */
    private function facts(Collection $cities, ?array $overlaps = null): array
    {
        $ids = $cities->modelKeys();
        $overlaps ??= $this->boundaries->overlapsFor($cities);

        $offered = DB::table('city_vehicle_classes as cvc')
            ->join('vehicle_classes', 'vehicle_classes.id', '=', 'cvc.vehicle_class_id')
            ->whereIn('cvc.city_id', $ids)
            ->where('cvc.is_active', true)
            ->where('vehicle_classes.is_active', true)
            ->groupBy('cvc.city_id')
            ->selectRaw('cvc.city_id, count(*) as total')
            ->pluck('total', 'city_id');

        $unpriced = $this->coverage->gaps($ids)->groupBy('city_id');

        $approved = $this->countByCity($this->approvedDrivers($ids));

        $plugIn = $this->countByCity($this->approvedDrivers($ids)
            ->whereHas('vehicle', fn ($q) => $q->whereIn('fuel_type', array_map(
                fn (FuelType $type) => $type->value,
                array_filter(FuelType::cases(), fn (FuelType $type) => $type->canCharge()),
            ))));

        $stations = $this->countByCity(EvChargingStation::active()->whereIn('city_id', $ids));

        $promos = DB::table('city_promo_code')
            ->join('promo_codes', 'promo_codes.id', '=', 'city_promo_code.promo_code_id')
            ->whereIn('city_promo_code.city_id', $ids)
            ->where('promo_codes.is_active', true)
            ->whereNull('promo_codes.cancelled_at')
            ->where('promo_codes.expires_at', '>', now())
            ->groupBy('city_promo_code.city_id')
            ->selectRaw('city_promo_code.city_id, count(distinct promo_codes.id) as total')
            ->pluck('total', 'city_id');

        return $cities->mapWithKeys(fn (City $city) => [$city->id => [
            'overlap' => ($overlaps[$city->id] ?? [])[0] ?? null,
            'offered_classes' => (int) $offered->get($city->id, 0),
            'unpriced_classes' => $unpriced->get($city->id, collect())->pluck('vehicle_class_name')->values()->all(),
            'approved_drivers' => (int) $approved->get($city->id, 0),
            'plug_in_drivers' => (int) $plugIn->get($city->id, 0),
            'active_stations' => (int) $stations->get($city->id, 0),
            'promo_codes' => (int) $promos->get($city->id, 0),
        ]])->all();
    }

    /**
     * @return SupportCollection<string, int>
     */
    private function countByCity(Builder $query): SupportCollection
    {
        return $query->toBase()
            ->groupBy('city_id')
            ->selectRaw('city_id, count(*) as total')
            ->pluck('total', 'city_id');
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    private function gates(City $city, array $facts, array $settings, bool $withNames): array
    {
        return [
            $this->boundaryGate($city, $facts['overlap']),
            $this->pricingGate($facts['offered_classes'], $facts['unpriced_classes']),
            $this->driversGate($facts['approved_drivers'], $settings['launch_min_approved_drivers']),
            $this->evChargingGate($facts['plug_in_drivers'], $facts['active_stations'], $settings['require_ev_charging']),
            $this->manualGate($city, CityReadinessGate::SupportCover, $withNames),
            $this->manualGate($city, CityReadinessGate::Permits, $withNames),
            $this->launchDateGate($city),
            $this->launchPromosGate($facts['promo_codes']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $gates
     * @return list<string>
     */
    private function missing(array $gates): array
    {
        return array_values(array_map(
            fn (array $gate) => $gate['gate'],
            array_filter($gates, fn (array $gate) => $gate['required'] && $gate['status'] === 'failed'),
        ));
    }

    /**
     * @param  list<string>  $cityIds
     * @return Builder<Driver>
     */
    private function approvedDrivers(array $cityIds): Builder
    {
        return Driver::whereIn('city_id', $cityIds)
            ->where('status', DriverStatus::Approved)
            ->where('kyc_status', KycStatus::Verified);
    }

    /**
     * @return array<string, mixed>
     */
    private function boundaryGate(City $city, ?City $overlap): array
    {
        if (! is_array($city->boundary) || $city->boundary === []) {
            return $this->gate(CityReadinessGate::Boundary, 'failed', 'No map boundary has been drawn.');
        }

        if ($overlap !== null) {
            return $this->gate(CityReadinessGate::Boundary, 'failed', "The boundary overlaps {$overlap->name}.", [
                'overlapping_city' => ['id' => $overlap->id, 'name' => $overlap->name],
            ]);
        }

        return $this->gate(CityReadinessGate::Boundary, 'passed', 'Boundary drawn with no overlap.');
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  list<string>  $unpriced
     * @return array<string, mixed>
     */
    private function pricingGate(int $offered, array $unpriced): array
    {
        if ($offered === 0) {
            return $this->gate(CityReadinessGate::Pricing, 'failed', 'No vehicle classes are offered in the city.', [
                'offered_classes' => 0,
                'unpriced_classes' => [],
            ]);
        }

        return $this->gate(
            CityReadinessGate::Pricing,
            $unpriced === [] ? 'passed' : 'failed',
            $unpriced === []
                ? "All {$offered} vehicle classes offered have prices in effect."
                : 'No prices in effect for: '.implode(', ', $unpriced).'.',
            ['offered_classes' => $offered, 'unpriced_classes' => $unpriced],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function driversGate(int $approved, int $minimum): array
    {
        return $this->gate(
            CityReadinessGate::Drivers,
            $approved >= $minimum ? 'passed' : 'failed',
            "{$approved} of {$minimum} approved, KYC-verified drivers.",
            ['approved' => $approved, 'minimum' => $minimum],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function evChargingGate(int $plugInDrivers, int $stations, bool $required): array
    {
        $data = ['plug_in_drivers' => $plugInDrivers, 'active_stations' => $stations];

        if (! $required || $plugInDrivers === 0) {
            return $this->gate(CityReadinessGate::EvCharging, 'not_applicable', $required
                ? 'No approved drivers with plug-in vehicles.'
                : 'Charging stations are not required (setting).', $data);
        }

        return $this->gate(
            CityReadinessGate::EvCharging,
            $stations > 0 ? 'passed' : 'failed',
            $stations > 0
                ? "{$stations} active charging stations for {$plugInDrivers} plug-in drivers."
                : "{$plugInDrivers} approved drivers have plug-in vehicles but the city has no active charging station.",
            $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manualGate(City $city, CityReadinessGate $gate, bool $withNames = true): array
    {
        $confirmation = ($city->readiness_confirmations ?? [])[$gate->value] ?? null;

        if ($confirmation === null) {
            return $this->gate($gate, 'failed', 'Not confirmed yet.', ['confirmation' => null]);
        }

        $detail = match (true) {
            $gate === CityReadinessGate::Permits && ($confirmation['value'] ?? null) === 'not_required' => 'Confirmed: no permit is required in this state.',
            $gate === CityReadinessGate::Permits => 'Confirmed: permits obtained.',
            default => 'Confirmed.',
        };

        // Named when shown, so a renamed admin shows their current name.
        if ($withNames) {
            $admin = isset($confirmation['confirmed_by_admin_id']) ? User::find($confirmation['confirmed_by_admin_id']) : null;
            $confirmation['confirmed_by'] = $admin ? [
                'id' => $admin->id,
                'name' => trim($admin->first_name.' '.$admin->last_name),
            ] : null;
        }

        return $this->gate($gate, 'passed', $detail, ['confirmation' => $confirmation]);
    }

    /**
     * @return array<string, mixed>
     */
    private function launchDateGate(City $city): array
    {
        return $city->launch_date === null
            ? $this->gate(CityReadinessGate::LaunchDate, 'failed', 'The client has not given a launch date yet.')
            : $this->gate(CityReadinessGate::LaunchDate, 'passed', 'Target launch date: '.$city->launch_date->toDateString().'.', [
                'launch_date' => $city->launch_date->toDateString(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function launchPromosGate(int $promos): array
    {
        return $this->gate(
            CityReadinessGate::LaunchPromos,
            $promos > 0 ? 'passed' : 'failed',
            $promos > 0 ? "{$promos} promo codes limited to the city." : 'No promo codes limited to the city (optional).',
            ['promo_codes' => $promos],
        );
    }

    /**
     * @param  'passed'|'failed'|'not_applicable'  $status
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function gate(CityReadinessGate $gate, string $status, string $detail, array $data = []): array
    {
        return [
            'gate' => $gate->value,
            'label' => $gate->label(),
            'kind' => $gate->isManual() ? 'manual' : 'computed',
            'required' => $gate->isRequired(),
            'status' => $status,
            'detail' => $detail,
            ...$data,
        ];
    }
}
