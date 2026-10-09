<?php

use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;

beforeEach(function () {
    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->create([
        'user_id' => $this->driverUser->id,
        'status' => 'approved',
    ]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->city = City::factory()->create(['currency_code' => 'NGN']);
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);
});

it('fetches cancellation reasons from public lookup endpoint with required schema', function () {
    $response = $this->getJson('/api/v1/lookup/cancellation-reasons');

    $response->assertOk()
        ->assertJsonStructure([
            'reasons' => [
                '*' => [
                    'title',
                    'description',
                    'code',
                    'allows_custom_description',
                ],
            ],
        ]);

    $reasons = $response->json('reasons');
    expect($reasons)->not->toBeEmpty();

    $otherOption = collect($reasons)->firstWhere('code', 'other');
    expect($otherOption)->not->toBeNull();
    expect($otherOption['title'])->toBe('Other');
    expect($otherOption['allows_custom_description'])->toBeTrue();
    expect($otherOption['description'])->not->toBeEmpty();

    $standardOption = collect($reasons)->firstWhere('code', 'changed_mind');
    expect($standardOption)->not->toBeNull();
    expect($standardOption['allows_custom_description'])->toBeFalse();
});

it('filters reasons by role parameter', function () {
    $passengerResponse = $this->getJson('/api/v1/lookup/cancellation-reasons?role=passenger');
    $passengerResponse->assertOk();
    $passengerCodes = collect($passengerResponse->json('reasons'))->pluck('code')->all();

    expect($passengerCodes)->toContain('changed_mind', 'wait_too_long', 'other');
    expect($passengerCodes)->not->toContain('passenger_no_show');

    $driverResponse = $this->getJson('/api/v1/lookup/cancellation-reasons?role=driver');
    $driverResponse->assertOk();
    $driverCodes = collect($driverResponse->json('reasons'))->pluck('code')->all();

    expect($driverCodes)->toContain('passenger_no_show', 'other');
    expect($driverCodes)->not->toContain('changed_mind', 'wait_too_long');
});

it('fetches cancellation reasons from authenticated rides endpoint for passenger', function () {
    $passengerResponse = $this->withToken($this->passengerToken)
        ->getJson('/api/v1/rides/cancellation-reasons');

    $passengerResponse->assertOk();
    $passengerCodes = collect($passengerResponse->json('reasons'))->pluck('code')->all();
    expect($passengerCodes)->toContain('changed_mind', 'driver_too_far', 'other');
    expect($passengerCodes)->not->toContain('passenger_no_show');
});

it('fetches cancellation reasons from authenticated rides endpoint for driver', function () {
    $driverResponse = $this->withToken($this->driverToken)
        ->getJson('/api/v1/rides/cancellation-reasons');

    $driverResponse->assertOk();
    $driverCodes = collect($driverResponse->json('reasons'))->pluck('code')->all();
    expect($driverCodes)->toContain('passenger_no_show', 'safety_concern', 'other');
    expect($driverCodes)->not->toContain('driver_too_far', 'wait_too_long');
});

it('allows cancelling a ride with custom description when reason is other', function () {
    $ride = Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $response = $this->withToken($this->passengerToken)
        ->postJson("/api/v1/rides/{$ride->id}/cancel", [
            'reason' => 'other',
            'custom_description' => 'Driver vehicle broke down before arrival.',
        ]);

    $response->assertOk()
        ->assertJsonPath('ride.status', 'cancelled');

    $this->assertDatabaseHas('rides', [
        'id' => $ride->id,
        'status' => 'cancelled',
        'cancellation_reason' => 'other',
        'cancellation_details' => 'Driver vehicle broke down before arrival.',
    ]);
});

it('normalizes others reason to other on cancellation', function () {
    $ride = Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $response = $this->withToken($this->passengerToken)
        ->postJson("/api/v1/rides/{$ride->id}/cancel", [
            'reason' => 'others',
            'description' => 'My schedule changed unexpectedly.',
        ]);

    $response->assertOk()
        ->assertJsonPath('ride.status', 'cancelled');

    $this->assertDatabaseHas('rides', [
        'id' => $ride->id,
        'status' => 'cancelled',
        'cancellation_reason' => 'other',
        'cancellation_details' => 'My schedule changed unexpectedly.',
    ]);
});
