<?php

use App\Contracts\PushNotificationGateway;
use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Enums\EvStationStatus;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ReservationService;

beforeEach(function () {
    $this->admin = User::factory()->admin(AdminRole::Operations)->create();
    $this->token = $this->admin->createToken('auth', ['admin'])->plainTextToken;
    $this->push = Mockery::spy(PushNotificationGateway::class);
    $this->app->instance(PushNotificationGateway::class, $this->push);
    $this->city = City::factory()->create();
});

function archiveTestStation(int $stalls = 2, array $attributes = []): EvChargingStation
{
    $station = EvChargingStation::factory()->create(['city_id' => test()->city->id, 'total_stalls' => $stalls, ...$attributes]);

    for ($i = 1; $i <= $stalls; $i++) {
        EvChargingStall::factory()->create(['station_id' => $station->id, 'stall_number' => $i, 'status' => EvStallStatus::Available]);
    }

    return $station;
}

function pastSessionOn(EvChargingStation $station, int $stallNumber): EvReservation
{
    return EvReservation::factory()->completed()->create([
        'station_id' => $station->id,
        'stall_id' => $station->stalls()->where('stall_number', $stallNumber)->value('id'),
    ]);
}

/** Drivers waiting for a stall, first in line first. The fee is waived so it never blocks the hand-over. */
function queueDrivers(EvChargingStation $station, int $count): array
{
    return collect(range(1, $count))->map(fn (int $position) => EvReservation::factory()->queued()->create([
        'station_id' => $station->id,
        'queue_position' => $position,
        'fee_waived' => true,
        'created_at' => now()->subMinutes(10 - $position),
    ]))->all();
}

// === Deleting a station ===

it('deletes a station that has past reservations, keeping their history', function () {
    $station = archiveTestStation();
    $past = pastSessionOn($station, 2);
    $this->withToken($this->token);

    $this->deleteJson("/api/v1/admin/ev-stations/{$station->id}")
        ->assertOk()
        ->assertJsonPath('message', 'EV charging station deleted.');

    $this->getJson("/api/v1/admin/ev-stations/{$station->id}")->assertNotFound();
    $this->getJson('/api/v1/admin/ev-stations')->assertJsonCount(0, 'stations');
    $this->getJson("/api/v1/ev-stations/{$station->id}")->assertNotFound();
    $this->deleteJson("/api/v1/admin/ev-stations/{$station->id}")->assertNotFound();

    $this->getJson("/api/v1/admin/ev-reservations/{$past->id}")
        ->assertOk()
        ->assertJsonPath('reservation.station_name', $station->name)
        ->assertJsonPath('reservation.assigned_bay', 'Bay 2 · DC fast charger');
    $this->getJson("/api/v1/admin/ev-reservations?city_id={$this->city->id}")
        ->assertOk()
        ->assertJsonPath('reservations.0.id', $past->id);

    expect(EvChargingStation::withTrashed()->find($station->id)->trashed())->toBeTrue()
        ->and($past->fresh()->stall_id)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['auditable_id' => $station->id, 'event' => 'ev_station_deleted']);
});

it('still refuses to delete a station a driver is charging at', function () {
    $station = archiveTestStation();
    EvReservation::factory()->active()->create([
        'station_id' => $station->id,
        'stall_id' => $station->stalls()->value('id'),
    ]);

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/ev-stations/{$station->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Cannot delete station with active reservations.');

    expect(EvChargingStation::find($station->id))->not->toBeNull();
});

it('takes no reservations or charge requests at a deleted station', function () {
    $station = archiveTestStation();
    $user = User::factory()->create(['type' => UserType::Driver]);
    $driver = Driver::factory()->create(['user_id' => $user->id, 'status' => DriverStatus::Approved]);
    Vehicle::factory()->electric()->create(['driver_id' => $driver->id]);
    $driverToken = $user->createToken('auth', ['driver'])->plainTextToken;
    $stale = $station->fresh();
    $station->delete();

    $this->withToken($driverToken)
        ->postJson("/api/v1/driver/ev-reservations/stations/{$station->id}/reserve")
        ->assertNotFound();
    $this->withToken($driverToken)
        ->postJson('/api/v1/driver/ev-reservations', ['battery_level' => 20, 'station_id' => $station->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['station_id']);

    // A driver who loaded the station just before it was deleted.
    expect(fn () => app(ReservationService::class)->reserve($stale, $user))
        ->toThrow(RuntimeException::class, 'This station is not currently operational.');
    expect(EvReservation::where('station_id', $station->id)->exists())->toBeFalse();
});

// === Removing and adding stalls ===

it('removes a stall that has past reservations, and brings the same stall back when added again', function () {
    $station = archiveTestStation(3);
    $past = pastSessionOn($station, 3);
    $stallThree = $past->stall_id;
    $this->withToken($this->token);

    $this->putJson("/api/v1/admin/ev-stations/{$station->id}", ['total_stalls' => 2])
        ->assertOk()
        ->assertJsonPath('station.total_stalls', 2)
        ->assertJsonCount(2, 'station.stalls')
        ->assertJsonPath('station.availability.total', 2);

    $this->getJson("/api/v1/admin/ev-reservations/{$past->id}")
        ->assertJsonPath('reservation.assigned_bay', 'Bay 3 · DC fast charger');

    $this->putJson("/api/v1/admin/ev-stations/{$station->id}", ['total_stalls' => 4])
        ->assertOk()
        ->assertJsonCount(4, 'station.stalls')
        ->assertJsonPath('station.stalls.2.id', $stallThree)
        ->assertJsonPath('station.stalls.2.status.value', 'available')
        ->assertJsonPath('station.stalls.3.stall_number', 4);
});

it('brings a removed stall back when Manage Stalls sends its number', function () {
    $station = archiveTestStation(3);
    $stallThree = pastSessionOn($station, 3)->stall_id;
    $this->withToken($this->token);
    $this->putJson("/api/v1/admin/ev-stations/{$station->id}", ['total_stalls' => 2])->assertOk();

    $this->putJson("/api/v1/admin/ev-stations/{$station->id}/stalls", ['stalls' => [['stall_number' => 3, 'status' => 'out_of_service']]])
        ->assertOk()
        ->assertJsonPath('station.total_stalls', 3)
        ->assertJsonPath('station.stalls.2.id', $stallThree)
        ->assertJsonPath('station.stalls.2.status.value', 'out_of_service');
});

// === Freed stalls go to the queue ===

it('hands a stall put back in use to the next driver in line', function () {
    $station = archiveTestStation(1);
    $station->stalls()->update(['status' => EvStallStatus::OutOfService]);
    [$first, $second] = queueDrivers($station, 2);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/ev-stations/{$station->id}/stalls", ['stalls' => [['stall_number' => 1, 'status' => 'available']]])
        ->assertOk()
        ->assertJsonPath('station.stalls.0.status.value', 'reserved')
        ->assertJsonPath('station.stalls.0.reserved_driver.id', $first->driver_id)
        ->assertJsonPath('station.availability.queue_length', 1);

    expect($first->fresh()->status)->toBe(EvReservationStatus::Reserved)
        ->and($second->fresh()->queue_position)->toBe(1);
    $this->push->shouldHaveReceived('sendToUser')
        ->with($first->driver_id, Mockery::on(fn (array $n) => $n['data']['type'] === 'ev_reservation_ready'))
        ->once();
});

it('hands every stall freed at once to the drivers in line', function () {
    $station = archiveTestStation(3);
    $station->stalls()->update(['status' => EvStallStatus::OutOfService]);
    [$first, $second, $third] = queueDrivers($station, 3);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/ev-stations/{$station->id}/stalls", ['stalls' => [
            ['stall_number' => 1, 'status' => 'available'],
            ['stall_number' => 2, 'status' => 'available'],
        ]])
        ->assertOk()
        ->assertJsonPath('station.availability.reserved', 2)
        ->assertJsonPath('station.availability.queue_length', 1);

    expect($first->fresh()->status)->toBe(EvReservationStatus::Reserved)
        ->and($second->fresh()->status)->toBe(EvReservationStatus::Reserved)
        ->and($third->fresh()->status)->toBe(EvReservationStatus::Queued)
        ->and($third->fresh()->queue_position)->toBe(1);
});

it('hands stalls added to a full station to the drivers in line', function () {
    $station = archiveTestStation(1);
    $station->stalls()->update(['status' => EvStallStatus::Occupied]);
    [$first] = queueDrivers($station, 1);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/ev-stations/{$station->id}", ['total_stalls' => 2])
        ->assertOk()
        ->assertJsonPath('station.stalls.1.status.value', 'reserved')
        ->assertJsonPath('station.stalls.1.reserved_driver.id', $first->driver_id);
});

it('keeps the queue waiting while the station is closed, and serves it when the station reopens', function () {
    $station = archiveTestStation(1, ['status' => EvStationStatus::Maintenance]);
    $station->stalls()->update(['status' => EvStallStatus::OutOfService]);
    [$first] = queueDrivers($station, 1);
    $this->withToken($this->token);

    $this->putJson("/api/v1/admin/ev-stations/{$station->id}/stalls", ['stalls' => [['stall_number' => 1, 'status' => 'available']]])
        ->assertJsonPath('station.stalls.0.status.value', 'available');
    expect($first->fresh()->status)->toBe(EvReservationStatus::Queued);

    $this->patchJson("/api/v1/admin/ev-stations/{$station->id}/status", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('station.stalls.0.status.value', 'reserved')
        ->assertJsonPath('station.stalls.0.reserved_driver.id', $first->driver_id);
});

it('leaves the queue alone when no stall frees up', function () {
    $station = archiveTestStation(2);
    $station->stalls()->update(['status' => EvStallStatus::Occupied]);
    [$first] = queueDrivers($station, 1);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/ev-stations/{$station->id}/stalls", ['stalls' => [['stall_number' => 2, 'status' => 'out_of_service']]])
        ->assertOk();

    expect($first->fresh()->status)->toBe(EvReservationStatus::Queued);
    $this->push->shouldNotHaveReceived('sendToUser');
});
