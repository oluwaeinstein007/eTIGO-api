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
        ->assertJsonPath('queue.0.vehicle.plate_number', 'ABJ-001')
        ->assertJsonPath('queue.1.vehicle.plate_number', 'ABJ-002')
        ->assertJsonPath('availability.queue_length', 2);

    expect($response->json('queue.0.wait_seconds'))->toBeGreaterThanOrEqual(1200);
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
