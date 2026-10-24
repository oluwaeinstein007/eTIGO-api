<?php

use App\Enums\CityLaunchStatus;
use App\Enums\DisputeStatus;
use App\Enums\DocumentType;
use App\Enums\DriverShiftStatus;
use App\Enums\LostItemStatus;
use App\Enums\MaintenanceItemStatus;
use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Enums\RepairRequestStatus;
use App\Enums\RideStatus;
use App\Models\Adjustment;
use App\Models\City;
use App\Models\Dispute;
use App\Models\DisputeRefundRequest;
use App\Models\Driver;
use App\Models\DriverAvailabilityPlan;
use App\Models\DriverDocument;
use App\Models\DriverRepair;
use App\Models\DriverShift;
use App\Models\Journal;
use App\Models\LostItem;
use App\Models\Payment;
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

    // ...and none looks wrong (size, shape, state).
    City::all()->each(fn (City $city) => expect(app(CityBoundaryService::class)->sanityWarnings($city->boundary, $city->state))->toBe([], "{$city->name} boundary"));

    $detection = app(CityDetectionService::class);
    foreach (NigerianCities::all() as $data) {
        foreach ($data['landmarks'] as [$lat, $lng, $label]) {
            expect($detection->pickupCity(null, $lat, $lng)?->slug)->toBe($data['slug'], "{$label} should be in {$data['slug']}");
        }
    }

    expect(City::where('launch_status', CityLaunchStatus::Live)->count())->toBe(count(NigerianCities::all()))
        ->and(app(PricingCoverageService::class)->gaps(City::live()->pluck('id')->all()))->toBeEmpty();

    // Every live city has drivers and trips; every pickup is in its city.
    foreach (City::live()->get() as $city) {
        expect(Driver::where('city_id', $city->id)->count())->toBeGreaterThan(0, "{$city->name} has drivers")
            ->and(Ride::where('city_id', $city->id)->where('status', RideStatus::Completed)->count())->toBeGreaterThan(0, "{$city->name} has trips");
    }
    Ride::all()->each(fn (Ride $ride) => expect($detection->pickupCity($ride->city_id, (float) $ride->pickup_lat, (float) $ride->pickup_lng)?->id)->toBe($ride->city_id));

    // Phase 2 screens have something in each state.
    expect(PromoCode::whereHas('cities')->count())->toBe(4)
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
        ->and(Payout::pluck('status')->unique()->count())->toBe(4)
        ->and(Payout::where('status', PayoutStatus::Processing)->count())->toBe(0)
        ->and(Payout::where('status', PayoutStatus::Reversed)->count())->toBe(1)
        ->and(Adjustment::count())->toBe(3)
        ->and(WalletTransaction::where('status', 'abandoned')->count())->toBeGreaterThan(0);

    // Details the screens rely on.
    expect(User::whereNull('phone_verified_at')->whereIn('type', ['passenger', 'driver'])->count())->toBe(0)
        ->and(PromoCode::whereHas('cities')->where('expires_at', '<=', now())->count())->toBe(0)
        ->and(DriverRepair::where('status', RepairRequestStatus::Scheduled)->first()->preferred_date->isFuture())->toBeTrue()
        ->and(DriverDocument::whereIn('type', [DocumentType::DrivingLicence, DocumentType::GovernmentId])->whereNotNull('vehicle_id')->count())->toBe(0)
        ->and(Payout::where('requested_at', '>', now())->count())->toBe(0);

    // Every wallet tip moved money in the ledger.
    Payment::where('tip_method', PaymentMethod::Wallet)->where('tip_amount', '>', 0)->get()
        ->each(fn (Payment $payment) => expect(Journal::where('idempotency_key', "wallet-tip-{$payment->ride_id}")->exists())->toBeTrue());

    // Every balance is backed by ledger entries.
    $this->artisan('ledger:check')->assertSuccessful();
});

it('adds nothing when run again', function () {
    $counts = fn () => [City::count(), User::count(), Driver::count(), Vehicle::count(), Ride::count(), Dispute::count(), LostItem::count(), Payout::count(), DriverShift::count(), PromoCode::count()];
    $before = $counts();

    $this->seed(DatabaseSeeder::class);

    expect($counts())->toBe($before);
    $this->artisan('ledger:check')->assertSuccessful();
});
