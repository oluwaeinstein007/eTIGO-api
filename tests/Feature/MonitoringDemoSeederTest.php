<?php

use App\Enums\RideStatus;
use App\Events\DriverLocationUpdated;
use App\Models\City;
use App\Models\Driver;
use App\Models\GamificationProfile;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use Database\Seeders\MonitoringDemoSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->locationService = Mockery::mock(DriverLocationService::class);
    $this->locationService->shouldReceive('updateLocation')->byDefault();
    $this->locationService->shouldReceive('getDriverLocation')->andReturnNull()->byDefault();
    $this->app->instance(DriverLocationService::class, $this->locationService);
});

it('seeds pending rides, a ride at every active stage and passengers in each tier', function () {
    City::factory()->create(['slug' => 'lagos', 'name' => 'Lagos']);
    VehicleClass::factory()->create(['name' => 'economy', 'display_name' => 'Economy']);
    VehicleClass::factory()->create(['name' => 'comfort', 'display_name' => 'Comfort']);

    app(MonitoringDemoSeeder::class)->run();
    $firstPendingRideIds = Ride::whereNull('driver_id')->pluck('id');
    app(MonitoringDemoSeeder::class)->run();

    $openRides = Ride::whereIn('status', [...RideStatus::activeStatuses(), RideStatus::NoDriverFound])->get();

    expect($openRides->whereNull('driver_id')->pluck('status')->map->value->sort()->values()->all())
        ->toBe(['no_driver_found', 'searching', 'searching'])
        ->and($openRides->whereNotNull('driver_id')->pluck('status')->map->value->sort()->values()->all())
        ->toBe(['driver_arrived', 'driver_en_route', 'in_progress', 'in_progress', 'matched'])
        ->and(Ride::whereIn('id', $firstPendingRideIds)->pluck('status')->unique()->all())
        ->toBe([RideStatus::Cancelled])
        ->and(SosIncident::active()->count())->toBe(1)
        ->and(Driver::where('is_online', true)->count())->toBe(10)
        ->and(GamificationProfile::pluck('current_tier')->unique()->sort()->values()->all())->toBe([1, 2, 3, 4, 5]);
});

it('moves a demo driver on its way towards the pickup', function () {
    Event::fake([DriverLocationUpdated::class]);

    $driverUser = User::factory()->driver()->create(['email' => 'driver.test@ops-demo.etigo.com']);
    $driver = Driver::factory()->approved()->for($driverUser)->create(['is_online' => true]);
    $ride = Ride::factory()->create([
        'driver_id' => $driverUser->id,
        'status' => RideStatus::DriverEnRoute,
        'pickup_lat' => 6.5000,
        'pickup_lng' => 3.4000,
    ]);

    $this->locationService->shouldReceive('getDriverLocation')
        ->with((string) $driver->id)
        ->andReturn(['lat' => 6.5200, 'lng' => 3.4000, 'heading' => 0.0, 'speed' => 0.0, 'timestamp' => now()->timestamp]);
    $this->locationService->shouldReceive('updateLocation')
        ->once()
        ->withArgs(fn ($id, $lat, $lng) => $id === (string) $driver->id && abs($lat - 6.5170) < 0.0001 && $lng === 3.4);

    $this->artisan('demo:simulate-drivers')->assertSuccessful();

    Event::assertDispatched(DriverLocationUpdated::class, fn (DriverLocationUpdated $event) => $event->rideId === $ride->id
        && $event->eta !== null);
});

it('does not simulate drivers in production', function () {
    $this->app['env'] = 'production';

    $this->artisan('demo:simulate-drivers')->assertFailed();
});
