<?php

use App\Models\City;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\SurgeRule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use App\Services\SurgePricingService;
use Carbon\Carbon;

beforeEach(function () {
    $this->service = new SurgePricingService;
    $this->city = City::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

it('returns multiplier of 1.0 when no surge rules exist', function () {
    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.0);
    expect($result['rule'])->toBeNull();
});

it('returns multiplier of 1.0 when all rules are inactive', function () {
    SurgeRule::factory()->inactive()->create([
        'city_id' => $this->city->id,
        'multiplier' => 2.0,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.0);
});

it('returns multiplier of 1.0 when rule is expired', function () {
    SurgeRule::factory()->expired()->create([
        'city_id' => $this->city->id,
        'multiplier' => 2.0,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.0);
});

it('returns multiplier of 1.0 when rule is in the future', function () {
    SurgeRule::factory()->future()->create([
        'city_id' => $this->city->id,
        'multiplier' => 2.0,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.0);
});

it('applies manual surge rule when active', function () {
    $rule = SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.8,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.8);
    expect($result['rule']->id)->toBe($rule->id);
});

it('applies time-based surge rule during matching schedule', function () {
    $frozen = Carbon::now($this->city->timezone)->setTime(12, 0);
    Carbon::setTestNow($frozen);

    $now = Carbon::now($this->city->timezone);

    $rule = SurgeRule::factory()->timeBased([
        'days_of_week' => [$now->dayOfWeekIso],
        'start_time' => $now->copy()->subHour()->format('H:i'),
        'end_time' => $now->copy()->addHour()->format('H:i'),
    ])->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.5,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.5);
    expect($result['rule']->id)->toBe($rule->id);

    Carbon::setTestNow();
});

it('does not apply time-based surge rule outside schedule', function () {
    $now = Carbon::now($this->city->timezone);

    SurgeRule::factory()->timeBased([
        'days_of_week' => [$now->dayOfWeekIso],
        'start_time' => $now->copy()->addHours(2)->format('H:i'),
        'end_time' => $now->copy()->addHours(4)->format('H:i'),
    ])->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.5,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.0);
});

it('does not apply time-based surge rule on a different day', function () {
    $now = Carbon::now($this->city->timezone);
    $otherDay = $now->dayOfWeekIso === 7 ? 1 : $now->dayOfWeekIso + 1;

    SurgeRule::factory()->timeBased([
        'days_of_week' => [$otherDay],
        'start_time' => $now->copy()->subHour()->format('H:i'),
        'end_time' => $now->copy()->addHour()->format('H:i'),
    ])->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.5,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.0);
});

it('selects highest priority rule when multiple rules match', function () {
    SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.3,
        'priority' => 1,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $highPriority = SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'multiplier' => 2.0,
        'priority' => 10,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(2.0);
    expect($result['rule']->id)->toBe($highPriority->id);
});

it('selects highest multiplier when same priority rules match', function () {
    SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.3,
        'priority' => 0,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $higherMultiplier = SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'multiplier' => 2.0,
        'priority' => 0,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(2.0);
    expect($result['rule']->id)->toBe($higherMultiplier->id);
});

it('applies city-wide rule when no vehicle-class-specific rule exists', function () {
    $vehicleClass = VehicleClass::factory()->create();

    $rule = SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => null,
        'multiplier' => 1.5,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id, $vehicleClass->id);

    expect($result['multiplier'])->toBe(1.5);
    expect($result['rule']->id)->toBe($rule->id);
});

it('applies vehicle-class-specific rule alongside city-wide rule', function () {
    $vehicleClass = VehicleClass::factory()->create();

    SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => null,
        'multiplier' => 1.3,
        'priority' => 0,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $specificRule = SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $vehicleClass->id,
        'multiplier' => 2.0,
        'priority' => 5,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id, $vehicleClass->id);

    expect($result['multiplier'])->toBe(2.0);
    expect($result['rule']->id)->toBe($specificRule->id);
});

it('does not apply surge rules from another city', function () {
    $otherCity = City::factory()->create();

    SurgeRule::factory()->manual()->create([
        'city_id' => $otherCity->id,
        'multiplier' => 2.5,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.0);
});

it('applies surge multiplier to fare amount', function () {
    expect($this->service->applySurge(1900.0, 1.5))->toBe(2850.0);
    expect($this->service->applySurge(1900.0, 1.0))->toBe(1900.0);
    expect($this->service->applySurge(1500.0, 2.0))->toBe(3000.0);
});

it('handles overnight time-based schedule on the same evening', function () {
    $tz = $this->city->timezone;
    Carbon::setTestNow(Carbon::parse('2026-09-30 23:30:00', $tz)->utc());

    $rule = SurgeRule::factory()->timeBased([
        'days_of_week' => [Carbon::now($tz)->dayOfWeekIso],
        'start_time' => '22:00',
        'end_time' => '06:00',
    ])->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.8,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.8);
    expect($result['rule']->id)->toBe($rule->id);

    Carbon::setTestNow();
});

it('handles overnight time-based schedule into the next morning', function () {
    $tz = $this->city->timezone;
    // Wednesday 22:00–06:00 schedule, tested at Thursday 02:00 in city timezone
    Carbon::setTestNow(Carbon::parse('2026-10-01 02:00:00', $tz)->utc());

    $wednesday = Carbon::parse('2026-09-30', $tz)->dayOfWeekIso; // 3

    $rule = SurgeRule::factory()->timeBased([
        'days_of_week' => [$wednesday],
        'start_time' => '22:00',
        'end_time' => '06:00',
    ])->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.8,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.8);
    expect($result['rule']->id)->toBe($rule->id);

    Carbon::setTestNow();
});

it('does not match overnight schedule when previous day is not in days_of_week', function () {
    $tz = $this->city->timezone;
    // Schedule for Monday only (22:00–06:00), tested at Thursday 03:00 in city timezone
    Carbon::setTestNow(Carbon::parse('2026-10-01 03:00:00', $tz)->utc());

    $monday = 1;

    SurgeRule::factory()->timeBased([
        'days_of_week' => [$monday],
        'start_time' => '22:00',
        'end_time' => '06:00',
    ])->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.8,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $result = $this->service->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.0);

    Carbon::setTestNow();
});

it('batch loads surge multipliers for multiple vehicle classes', function () {
    $vc1 = VehicleClass::factory()->create();
    $vc2 = VehicleClass::factory()->create();

    SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => null,
        'multiplier' => 1.3,
        'priority' => 0,
        'created_by_admin_id' => $this->admin->id,
    ]);

    SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $vc1->id,
        'multiplier' => 2.0,
        'priority' => 5,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $results = $this->service->getMultipliersForCity($this->city->id, [$vc1->id, $vc2->id]);

    expect($results[$vc1->id]['multiplier'])->toBe(2.0);
    expect($results[$vc2->id]['multiplier'])->toBe(1.3);
});

// === Demand-based surge ===

/**
 * @param  list<string>  $freshDriverIds  Drivers whose app reported a location just now
 */
function demandService(array $freshDriverIds): SurgePricingService
{
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('getDriverLocation')->andReturnUsing(fn (string $id) => in_array($id, $freshDriverIds, true)
        ? ['lat' => 6.5, 'lng' => 3.4, 'heading' => 0.0, 'speed' => 0.0, 'timestamp' => now()->timestamp]
        : ['lat' => 6.5, 'lng' => 3.4, 'heading' => 0.0, 'speed' => 0.0, 'timestamp' => now()->subMinutes(10)->timestamp]);

    return new SurgePricingService($locations);
}

function sharedVehicleClassId(): string
{
    return VehicleClass::query()->value('id') ?? VehicleClass::factory()->create()->id;
}

function idleDriverIn(City $city): Driver
{
    $driver = Driver::factory()->approved()->create(['city_id' => $city->id, 'is_online' => true]);
    Vehicle::factory()->create(['driver_id' => $driver->id, 'vehicle_class_id' => sharedVehicleClassId()]);

    return $driver;
}

function requestsIn(City $city, int $passengers, ?Carbon $at = null): void
{
    Ride::factory()->count($passengers)->create(['city_id' => $city->id, 'vehicle_class_id' => sharedVehicleClassId(), 'created_at' => $at ?? now()]);
}

it('applies a demand-based surge when requests outnumber available drivers', function () {
    SurgeRule::factory()->demandBased(2.0)->create(['city_id' => $this->city->id, 'multiplier' => 1.5, 'created_by_admin_id' => $this->admin->id]);
    $drivers = [idleDriverIn($this->city), idleDriverIn($this->city)];
    requestsIn($this->city, 6);

    $result = demandService(array_map(fn ($d) => $d->id, $drivers))->getCurrentMultiplier($this->city->id);

    expect($result['multiplier'])->toBe(1.5);
});

it('does not count drivers who stopped reporting their location or are on a trip', function () {
    $service = demandService([($fresh = idleDriverIn($this->city))->id, ($busy = idleDriverIn($this->city))->id]);
    idleDriverIn($this->city); // stale location
    Ride::factory()->inProgress()->create(['city_id' => $this->city->id, 'vehicle_class_id' => sharedVehicleClassId(), 'driver_id' => $busy->user_id, 'created_at' => now()->subHour()]);
    requestsIn($this->city, 4);

    expect($service->demandSnapshot($this->city->id))
        ->toMatchArray(['requests' => 4, 'available_drivers' => 1, 'ratio' => 4.0]);
});

it('needs a minimum number of requests before demand surge fires', function () {
    SurgeRule::factory()->demandBased(1.5)->create(['city_id' => $this->city->id, 'multiplier' => 1.8, 'created_by_admin_id' => $this->admin->id]);
    requestsIn($this->city, 2);

    expect(demandService([])->getCurrentMultiplier($this->city->id)['multiplier'])->toBe(1.0);
});

it('only counts requests within the demand window', function () {
    $driver = idleDriverIn($this->city);
    requestsIn($this->city, 3);
    requestsIn($this->city, 5, now()->subMinutes(config('surge.demand_window_minutes') + 1));

    expect(demandService([$driver->id])->demandSnapshot($this->city->id)['requests'])->toBe(3);
});
