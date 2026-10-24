<?php

use App\Enums\CityLaunchStatus;
use App\Enums\DisputeStatus;
use App\Enums\DriverShiftStatus;
use App\Enums\LostItemStatus;
use App\Enums\MaintenanceItemStatus;
use App\Enums\PayoutStatus;
use App\Enums\RideStatus;
use App\Models\Adjustment;
use App\Models\City;
use App\Models\CityWaitlistEntry;
use App\Models\Dispute;
use App\Models\DisputeRefundRequest;
use App\Models\Driver;
use App\Models\DriverAvailabilityPlan;
use App\Models\DriverRepair;
use App\Models\DriverShift;
use App\Models\LostItem;
use App\Models\Payout;
use App\Models\PromoCode;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceItem;
use App\Models\VehicleMaintenanceRecord;
use App\Models\WalletTransaction;
use App\Services\CityBoundaryService;
use App\Services\CityDetectionService;
use App\Services\PricingCoverageService;
use Database\Seeders\Data\NigerianCities;
use Database\Seeders\DatabaseSeeder;

/*
| The demo data staging and local databases are built from: cities that
| cover Nigeria without overlapping, priced, with trips and money that went
| through the ledger, and every Phase 2 screen populated. Seeding twice adds
| nothing.
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('seeds consistent data across Nigerian cities', function () {
    // Cities: all drawn, none overlapping, each landmark inside its own city.
    expect(City::whereIn('slug', array_column(NigerianCities::all(), 'slug'))->count())->toBe(count(NigerianCities::all()));
    expect(collect(app(CityBoundaryService::class)->overlapsFor(City::all()))->filter()->keys()->all())->toBe([]);

    $detection = app(CityDetectionService::class);
    foreach (NigerianCities::all() as $data) {
        foreach ($data['landmarks'] as [$lat, $lng, $label]) {
            expect($detection->pickupCity(null, $lat, $lng)?->slug)->toBe($data['slug'], "{$label} should be in {$data['slug']}");
        }
    }

    expect(City::where('launch_status', CityLaunchStatus::Live)->count())->toBeGreaterThanOrEqual(11)
        ->and(City::where('launch_status', CityLaunchStatus::Onboarding)->count())->toBeGreaterThanOrEqual(10)
        ->and(City::where('launch_status', CityLaunchStatus::Paused)->count())->toBe(1)
        ->and(app(PricingCoverageService::class)->gaps(City::live()->pluck('id')->all()))->toBeEmpty();

    // Every live city has drivers and trips; every pickup is in its city.
    foreach (City::live()->get() as $city) {
        expect(Driver::where('city_id', $city->id)->count())->toBeGreaterThan(0, "{$city->name} has drivers")
            ->and(Ride::where('city_id', $city->id)->where('status', RideStatus::Completed)->count())->toBeGreaterThan(0, "{$city->name} has trips");
    }
    Ride::all()->each(fn (Ride $ride) => expect($detection->pickupCity($ride->city_id, (float) $ride->pickup_lat, (float) $ride->pickup_lng)?->id)->toBe($ride->city_id));

    // Phase 2 screens have something in each state.
    expect(CityWaitlistEntry::count())->toBeGreaterThan(10)
        ->and(PromoCode::whereHas('cities')->count())->toBe(4)
        ->and(ScheduledRide::count())->toBeGreaterThanOrEqual(5)
        ->and(Dispute::pluck('status')->unique()->sort()->values()->all())->toEqualCanonicalizing([DisputeStatus::Open, DisputeStatus::UnderReview, DisputeStatus::Resolved, DisputeStatus::Dismissed])
        ->and(Dispute::where('escalation_tier', 2)->count())->toBeGreaterThanOrEqual(2)
        ->and(DisputeRefundRequest::count())->toBe(2)
        ->and(LostItem::pluck('status')->unique()->count())->toBe(count(LostItemStatus::cases()))
        ->and(DriverAvailabilityPlan::count())->toBeGreaterThan(20)
        ->and(DriverShift::pluck('status')->unique()->count())->toBe(4)
        ->and(DriverShift::where('status', DriverShiftStatus::Missed)->count())->toBe(1)
        ->and(VehicleMaintenanceRecord::count())->toBeGreaterThan(10)
        ->and(VehicleMaintenanceItem::where('status', MaintenanceItemStatus::Expired)->count())->toBeGreaterThanOrEqual(1)
        ->and(VehicleMaintenanceItem::where('status', MaintenanceItemStatus::Overdue)->count())->toBeGreaterThanOrEqual(1)
        ->and(DriverRepair::count())->toBe(3)
        ->and(Payout::pluck('status')->unique()->count())->toBe(5)
        ->and(Payout::where('status', PayoutStatus::Reversed)->count())->toBe(1)
        ->and(Adjustment::count())->toBe(3)
        ->and(WalletTransaction::where('status', 'abandoned')->count())->toBeGreaterThan(0);

    // Every balance is backed by ledger entries.
    $this->artisan('ledger:check')->assertSuccessful();
});

it('adds nothing when run again', function () {
    $counts = fn () => [City::count(), User::count(), Driver::count(), Vehicle::count(), Ride::count(), Dispute::count(), LostItem::count(), Payout::count(), DriverShift::count()];
    $before = $counts();

    $this->seed(DatabaseSeeder::class);

    expect($counts())->toBe($before);
    $this->artisan('ledger:check')->assertSuccessful();
});
