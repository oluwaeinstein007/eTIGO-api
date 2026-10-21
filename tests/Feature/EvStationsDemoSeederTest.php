<?php

use App\Enums\AdminRole;
use App\Enums\EvReservationStatus;
use App\Models\City;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\User;
use App\Models\VehicleClass;
use Database\Seeders\EvStationsDemoSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    City::factory()->create(['slug' => 'lagos', 'name' => 'Lagos']);
    VehicleClass::factory()->create(['name' => 'economy', 'display_name' => 'Economy']);
});

it('seeds a station with drivers charging and queueing, and replaces them on a re-run', function () {
    app(EvStationsDemoSeeder::class)->run();
    app(EvStationsDemoSeeder::class)->run();

    $station = EvChargingStation::where('name', EvStationsDemoSeeder::STATION_NAME)->sole();

    expect($station->stalls()->count())->toBe(3)
        ->and(EvReservation::where('station_id', $station->id)->where('status', EvReservationStatus::Active)->count())->toBe(2)
        ->and(EvReservation::where('station_id', $station->id)->where('status', EvReservationStatus::Queued)->count())->toBe(3)
        ->and(User::where('email', 'like', '%.ev@%')->count())->toBe(5);

    Sanctum::actingAs(User::factory()->admin(AdminRole::Operations)->create(), ['admin']);

    $response = $this->getJson("/api/v1/admin/ev-stations/{$station->id}")
        ->assertOk()
        ->assertJsonPath('station.availability', [
            'total' => 3, 'available' => 0, 'occupied' => 2, 'reserved' => 0, 'out_of_service' => 1, 'queue_length' => 3,
        ])
        ->assertJsonPath('station.stalls.0.current_driver.name', 'Ifeoma Chukwu')
        ->assertJsonPath('station.stalls.0.current_driver.vehicle.plate_number', 'LND-241EV')
        ->assertJsonPath('station.stalls.1.current_driver.name', 'Dapo Adeyemi')
        ->assertJsonPath('station.stalls.2.status.value', 'out_of_service')
        ->assertJsonPath('station.stalls.2.current_driver', null)
        ->assertJsonCount(3, 'queue')
        ->assertJsonPath('queue.0.position', 1)
        ->assertJsonPath('queue.0.driver.name', 'Zainab Lawal')
        ->assertJsonPath('queue.0.driver.vehicle.plate_number', 'LND-243EV')
        ->assertJsonPath('queue.2.driver.name', 'Sade Ogunbiyi');

    expect($response->json('station.stalls.0.occupied_since'))->not->toBeNull()
        ->and($response->json('station.stalls.1.estimated_departure_at'))->not->toBeNull()
        ->and($response->json('station.stalls.0.updated_at'))->not->toBeNull()
        ->and($response->json('queue.0.wait_seconds'))->toBeGreaterThanOrEqual(14 * 60);
});

it('re-runs after the station was given an extra stall with a reservation on it', function () {
    app(EvStationsDemoSeeder::class)->run();

    $station = EvChargingStation::where('name', EvStationsDemoSeeder::STATION_NAME)->sole();
    $extra = $station->stalls()->create(['stall_number' => 4, 'status' => 'reserved']);
    EvReservation::factory()->create(['station_id' => $station->id, 'stall_id' => $extra->id, 'status' => EvReservationStatus::Reserved]);

    app(EvStationsDemoSeeder::class)->run();

    expect($station->stalls()->pluck('stall_number')->sort()->values()->all())->toBe([1, 2, 3])
        ->and(EvReservation::where('station_id', $station->id)->count())->toBe(5);
});
