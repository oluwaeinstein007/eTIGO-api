<?php

use App\Enums\AdminRole;
use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Events\EvStationAvailabilityChanged;
use App\Models\City;
use App\Models\Driver;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CityDetectionService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->admin = User::factory()->admin(AdminRole::Operations)->create();
    $this->token = $this->admin->createToken('auth', ['admin'])->plainTextToken;
    $this->city = City::factory()->create();
});

function stationWithStalls(int $count = 4): EvChargingStation
{
    $station = EvChargingStation::factory()->create(['total_stalls' => $count]);

    for ($i = 1; $i <= $count; $i++) {
        EvChargingStall::factory()->create([
            'station_id' => $station->id,
            'stall_number' => $i,
            'status' => EvStallStatus::Available,
        ]);
    }

    return $station;
}

it('returns the charging details it saves', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/ev-stations', [
            'name' => 'Wuse Hub',
            'city_id' => $this->city->id,
            'lat' => 9.07,
            'lng' => 7.49,
            'address' => '12 Aminu Kano Crescent',
            'total_stalls' => 2,
            'connector_types' => ['CCS2', 'Type2'],
            'power_output_kw' => 60,
            'pricing_per_kwh' => 25000,
            'operating_hours' => ['open' => '06:00', 'close' => '22:00'],
        ])
        ->assertCreated()
        ->assertJsonPath('station.connector_types', ['CCS2', 'Type2'])
        ->assertJsonPath('station.power_output_kw', 60)
        ->assertJsonPath('station.pricing_per_kwh', 25000)
        ->assertJsonPath('station.operating_hours.open', '06:00');

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/ev-stations/'.$response->json('station.id'))
        ->assertJsonPath('station.connector_types', ['CCS2', 'Type2']);
});

it('works out the city from the coordinates when none is given', function () {
    $detection = Mockery::mock(CityDetectionService::class);
    $detection->shouldReceive('detectCity')->with(9.07, 7.49)->andReturn(['city' => $this->city, 'resolved_address' => null]);
    $this->app->instance(CityDetectionService::class, $detection);

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/ev-stations', [
            'name' => 'Wuse Hub', 'lat' => 9.07, 'lng' => 7.49, 'address' => '12 Aminu Kano Crescent', 'total_stalls' => 2,
        ])
        ->assertCreated()
        ->assertJsonPath('station.city_id', $this->city->id);
});

it('asks for the city when the coordinates are outside every city', function () {
    $detection = Mockery::mock(CityDetectionService::class);
    $detection->shouldReceive('detectCity')->andReturn(['city' => null, 'resolved_address' => null]);
    $this->app->instance(CityDetectionService::class, $detection);

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/ev-stations', [
            'name' => 'Nowhere', 'lat' => 1.0, 'lng' => 1.0, 'address' => 'Gulf of Guinea', 'total_stalls' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('city_id');
});

it('adds stalls when the stall count goes up on edit', function () {
    $station = stationWithStalls(2);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/ev-stations/{$station->id}", ['total_stalls' => 4])
        ->assertOk()
        ->assertJsonPath('station.total_stalls', 4);

    expect($station->stalls()->orderBy('stall_number')->pluck('stall_number')->all())->toBe([1, 2, 3, 4]);
});

it('removes the highest-numbered free stalls when the count goes down', function () {
    $station = stationWithStalls(4);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/ev-stations/{$station->id}", ['total_stalls' => 2])
        ->assertOk()
        ->assertJsonPath('station.total_stalls', 2);

    expect($station->stalls()->orderBy('stall_number')->pluck('stall_number')->all())->toBe([1, 2]);
});

it('will not remove a stall that is in use', function () {
    $station = stationWithStalls(3);
    $station->stalls()->where('stall_number', 3)->update(['status' => EvStallStatus::Occupied]);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/ev-stations/{$station->id}", ['total_stalls' => 2])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['total_stalls' => 'Stall 3 is in use. Free it before reducing the stall count.']);

    expect($station->stalls()->count())->toBe(3)
        ->and($station->fresh()->total_stalls)->toBe(3);
});

it('lists drivers in the queue in the order they joined, with their vehicle', function () {
    $station = stationWithStalls(1);

    $queued = collect([2, 1])->map(function (int $position) use ($station) {
        $user = User::factory()->driver()->create(['first_name' => "Driver{$position}"]);
        $driver = Driver::factory()->create(['user_id' => $user->id]);
        Vehicle::factory()->create(['driver_id' => $driver->id, 'plate_number' => "ABJ-00{$position}"]);

        return EvReservation::factory()->create([
            'station_id' => $station->id,
            'driver_id' => $user->id,
            'status' => EvReservationStatus::Queued,
            'queue_position' => $position,
            'created_at' => now()->subMinutes(10 * (3 - $position)),
        ]);
    });

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/admin/ev-stations/{$station->id}")
        ->assertOk()
        ->assertJsonCount(2, 'queue')
        ->assertJsonPath('queue.0.position', 1)
        ->assertJsonPath('queue.0.driver.name', fn ($name) => str_starts_with($name, 'Driver1'))
        ->assertJsonPath('queue.0.driver.vehicle.plate_number', 'ABJ-001')
        ->assertJsonPath('queue.1.driver.vehicle.plate_number', 'ABJ-002')
        ->assertJsonPath('station.availability.queue_length', 2)
        ->assertJsonPath('availability.queue_length', 2)
        ->assertJsonMissingPath('queue.0.vehicle');

    expect($response->json('queue.0.wait_seconds'))->toBeGreaterThanOrEqual(1200);
});

it('shows the driver charging at a stall in the same shape as a queued driver', function () {
    $station = stationWithStalls(2);
    $user = User::factory()->driver()->create(['first_name' => 'Ifeoma', 'last_name' => 'Chukwu', 'phone' => '+2348300000041']);
    $driver = Driver::factory()->create(['user_id' => $user->id]);
    Vehicle::factory()->electric()->create([
        'driver_id' => $driver->id, 'make' => 'BYD', 'model' => 'Dolphin', 'colour' => 'White', 'plate_number' => 'LND-241EV',
    ]);
    $station->stalls()->where('stall_number', 1)->update([
        'status' => EvStallStatus::Occupied,
        'current_vehicle_driver_id' => $user->id,
        'occupied_since' => now()->subMinutes(25),
        'estimated_departure_at' => now()->addMinutes(25),
    ]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/admin/ev-stations/{$station->id}")
        ->assertOk()
        ->assertJsonPath('station.stalls.0.current_driver', [
            'id' => $user->id,
            'name' => 'Ifeoma Chukwu',
            'phone' => '+2348300000041',
            'vehicle' => ['make' => 'BYD', 'model' => 'Dolphin', 'colour' => 'White', 'plate_number' => 'LND-241EV'],
        ])
        ->assertJsonPath('station.stalls.1.current_driver', null);

    expect($response->json('station.stalls.0.occupied_since'))->not->toBeNull()
        ->and($response->json('station.stalls.0.estimated_departure_at'))->not->toBeNull();

    // The list sends the same stall shape.
    $this->withToken($this->token)
        ->getJson('/api/v1/admin/ev-stations')
        ->assertJsonPath('stations.0.stalls.0.current_driver.vehicle.plate_number', 'LND-241EV')
        ->assertJsonPath('stations.0.stalls.1.current_driver', null);
});

it('keeps the charging driver off the public station endpoints', function () {
    $station = stationWithStalls(1);
    $station->stalls()->update([
        'status' => EvStallStatus::Occupied,
        'current_vehicle_driver_id' => User::factory()->driver()->create()->id,
    ]);

    $this->getJson("/api/v1/ev-stations/{$station->id}")
        ->assertOk()
        ->assertJsonMissingPath('station.stalls.0.current_driver')
        ->assertJsonMissingPath('station.availability.queue_length');
});

it('sends queue_length inside station.availability on every admin station response', function () {
    $station = stationWithStalls(2);
    EvReservation::factory()->queued()->create(['station_id' => $station->id]);
    $this->withToken($this->token);

    $this->getJson("/api/v1/admin/ev-stations/{$station->id}")
        ->assertJsonPath('station.availability.queue_length', 1);
    $this->putJson("/api/v1/admin/ev-stations/{$station->id}", ['name' => 'Renamed'])
        ->assertJsonPath('station.availability.queue_length', 1);
    $this->patchJson("/api/v1/admin/ev-stations/{$station->id}/status", ['status' => 'maintenance'])
        ->assertJsonPath('station.availability.queue_length', 1);
    $this->putJson("/api/v1/admin/ev-stations/{$station->id}/stalls", ['stalls' => [['stall_number' => 1, 'status' => 'available']]])
        ->assertJsonPath('station.availability.queue_length', 1)
        ->assertJsonPath('station.city.id', $station->city_id);
    $this->postJson('/api/v1/admin/ev-stations', [
        'name' => 'New Hub', 'city_id' => $this->city->id, 'lat' => 9.07, 'lng' => 7.49, 'address' => 'Wuse', 'total_stalls' => 1,
    ])->assertJsonPath('station.availability.queue_length', 0);
});

it('clears the driver when an admin frees a stall by hand', function () {
    $station = stationWithStalls(2);
    $station->stalls()->where('stall_number', 1)->update([
        'status' => EvStallStatus::Occupied,
        'current_vehicle_driver_id' => User::factory()->driver()->create()->id,
        'occupied_since' => now()->subMinutes(30),
        'estimated_departure_at' => now()->addMinutes(10),
    ]);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/ev-stations/{$station->id}/stalls", ['stalls' => [
            ['stall_number' => 1, 'status' => 'available'],
            ['stall_number' => 2, 'status' => 'occupied'],
        ]])
        ->assertOk()
        ->assertJsonPath('station.stalls.0.current_driver', null)
        ->assertJsonPath('station.stalls.0.occupied_since', null)
        ->assertJsonPath('station.stalls.0.estimated_departure_at', null)
        ->assertJsonPath('station.stalls.1.status.value', 'occupied')
        ->assertJsonPath('station.stalls.1.current_driver', null);

    expect($station->stalls()->where('stall_number', 2)->value('occupied_since'))->not->toBeNull();
});

it('counts the queue on the station list', function () {
    $station = stationWithStalls(2);
    EvReservation::factory()->queued()->create(['station_id' => $station->id]);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/ev-stations')
        ->assertJsonPath('stations.0.availability.total', 2)
        ->assertJsonPath('stations.0.availability.available', 2)
        ->assertJsonPath('stations.0.availability.queue_length', 1);
});

it('refuses to delete a station with a reservation awaiting review', function () {
    $station = stationWithStalls(1);
    EvReservation::factory()->create(['station_id' => $station->id, 'status' => EvReservationStatus::PendingReview]);

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/ev-stations/{$station->id}")
        ->assertUnprocessable();
});

it('broadcasts when a stall or the queue changes', function () {
    $station = stationWithStalls(2);
    Event::fake([EvStationAvailabilityChanged::class]);

    $station->stalls()->first()->update(['status' => EvStallStatus::Occupied]);
    EvReservation::factory()->queued()->create(['station_id' => $station->id]);

    Event::assertDispatchedTimes(EvStationAvailabilityChanged::class, 2);
    Event::assertDispatched(EvStationAvailabilityChanged::class, fn ($e) => $e->stationId === $station->id
        && $e->broadcastAs() === 'ev.station.availability_changed'
        && $e->broadcastWith()['availability']['occupied'] === 1);
});

it('does not broadcast a stall edit that leaves the availability alone', function () {
    $station = stationWithStalls(1);
    Event::fake([EvStationAvailabilityChanged::class]);

    $station->stalls()->first()->update(['estimated_departure_at' => now()->addHour()]);

    Event::assertNotDispatched(EvStationAvailabilityChanged::class);
});
