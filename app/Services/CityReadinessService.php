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
use App\Models\PromoCode;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
        $approvedDrivers = $this->approvedDrivers($city)->count();

        $gates = [
            $this->boundaryGate($city),
            $this->pricingGate($city),
            $this->driversGate($approvedDrivers, $settings['launch_min_approved_drivers']),
            $this->evChargingGate($city, $settings['require_ev_charging']),
            $this->manualGate($city, CityReadinessGate::SupportCover),
            $this->manualGate($city, CityReadinessGate::Permits),
            $this->launchDateGate($city),
            $this->launchPromosGate($city),
        ];

        $missing = array_values(array_map(
            fn (array $gate) => $gate['gate'],
            array_filter($gates, fn (array $gate) => $gate['required'] && $gate['status'] === 'failed'),
        ));

        return [
            'ready' => $missing === [],
            'missing' => $missing,
            'gates' => $gates,
            'counts' => [
                'approved_drivers' => $approvedDrivers,
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
     * @return Builder<Driver>
     */
    private function approvedDrivers(City $city): Builder
    {
        return Driver::where('city_id', $city->id)
            ->where('status', DriverStatus::Approved)
            ->where('kyc_status', KycStatus::Verified);
    }

    /**
     * @return array<string, mixed>
     */
    private function boundaryGate(City $city): array
    {
        if (! is_array($city->boundary) || $city->boundary === []) {
            return $this->gate(CityReadinessGate::Boundary, 'failed', 'No map boundary has been drawn.');
        }

        $overlap = $this->boundaries->findOverlappingCity($city->boundary, $city->id);

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
    private function pricingGate(City $city): array
    {
        $offered = $city->vehicleClasses()
            ->wherePivot('is_active', true)
            ->where('vehicle_classes.is_active', true)
            ->count();

        if ($offered === 0) {
            return $this->gate(CityReadinessGate::Pricing, 'failed', 'No vehicle classes are offered in the city.', [
                'offered_classes' => 0,
                'unpriced_classes' => [],
            ]);
        }

        $gaps = $this->coverage->gaps([$city->id]);
        $unpriced = $gaps->pluck('vehicle_class_name')->values()->all();

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
    private function evChargingGate(City $city, bool $required): array
    {
        $plugInDrivers = $this->approvedDrivers($city)
            ->whereHas('vehicle', fn ($q) => $q->whereIn('fuel_type', array_map(
                fn (FuelType $type) => $type->value,
                array_filter(FuelType::cases(), fn (FuelType $type) => $type->canCharge()),
            )))
            ->count();

        $stations = EvChargingStation::active()->where('city_id', $city->id)->count();
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
    private function manualGate(City $city, CityReadinessGate $gate): array
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
        $admin = isset($confirmation['confirmed_by_admin_id']) ? User::find($confirmation['confirmed_by_admin_id']) : null;
        $confirmation['confirmed_by'] = $admin ? [
            'id' => $admin->id,
            'name' => trim($admin->first_name.' '.$admin->last_name),
        ] : null;

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
    private function launchPromosGate(City $city): array
    {
        $promos = PromoCode::whereHas('cities', fn ($q) => $q->whereKey($city->id))
            ->where('is_active', true)
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->count();

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
