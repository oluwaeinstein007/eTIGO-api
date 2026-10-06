<?php

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Events\DriverLocationUpdated;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverLocationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->driverUser = User::factory()->driver()->create();
    $this->driver = Driver::factory()->approved()->for($this->driverUser)->create(['is_online' => true]);
    Vehicle::factory()->for($this->driver)->create();
    $this->token = $this->driverUser->createToken('driver-auth', ['driver'])->plainTextToken;

    RateLimiter::clear("driver_location:{$this->driver->id}");

    $this->mockLocationService = Mockery::mock(DriverLocationService::class);
    $this->mockLocationService->shouldReceive('updateLocation')->andReturnNull();
    $this->mockLocationService->shouldReceive('removeDriver')->andReturnNull();
    $this->mockLocationService->shouldReceive('getDriverLocation')->andReturnNull();
    $this->app->instance(DriverLocationService::class, $this->mockLocationService);
});

it('accepts location update from an online approved driver', function () {
    Event::fake([DriverLocationUpdated::class]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/driver/location', [
            'lat' => 9.0579,
            'lng' => 7.4951,
            'heading' => 45.0,
            'speed' => 30.0,
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Location updated.']);

    Event::assertDispatched(DriverLocationUpdated::class, function ($event) {
        return $event->driverId === $this->driver->id
            && $event->location['lat'] === 9.0579
            && $event->location['lng'] === 7.4951;
    });
});

it('calls location service with correct parameters', function () {
    Event::fake([DriverLocationUpdated::class]);

    $this->withToken($this->token)
        ->postJson('/api/v1/driver/location', [
            'lat' => 9.0579,
            'lng' => 7.4951,
            'heading' => 45.0,
            'speed' => 30.0,
        ])
        ->assertOk();

    $this->mockLocationService->shouldHaveReceived('updateLocation')
        ->once()
        ->with($this->driver->id, 9.0579, 7.4951, 45.0, 30.0);
});

it('returns 403 for offline driver', function () {
    $this->driver->update(['is_online' => false]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/driver/location', [
            'lat' => 9.0579,
            'lng' => 7.4951,
        ]);

    $response->assertForbidden();
});

it('returns 403 for unapproved driver', function () {
    $this->driver->update(['status' => DriverStatus::PendingReview, 'is_online' => false]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/driver/location', [
            'lat' => 9.0579,
            'lng' => 7.4951,
        ]);

    $response->assertForbidden();
});

it('returns 401 without authentication', function () {
    $response = $this->postJson('/api/v1/driver/location', [
        'lat' => 9.0579,
        'lng' => 7.4951,
    ]);

    $response->assertUnauthorized();
});

it('returns 403 for passenger user type', function () {
    $passenger = User::factory()->create(['type' => UserType::Passenger]);
    $token = $passenger->createToken('auth', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/location', [
            'lat' => 9.0579,
            'lng' => 7.4951,
        ]);

    $response->assertForbidden();
});

it('returns 422 for invalid coordinates', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/driver/location', [
            'lat' => 100.0,
            'lng' => 200.0,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['lat', 'lng']);
});

it('returns 422 for missing required fields', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/driver/location', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['lat', 'lng']);
});

it('returns 429 when rate limited', function () {
    Event::fake([DriverLocationUpdated::class]);

    $this->withToken($this->token)
        ->postJson('/api/v1/driver/location', [
            'lat' => 9.0579,
            'lng' => 7.4951,
        ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/driver/location', [
            'lat' => 9.0580,
            'lng' => 7.4952,
        ]);

    $response->assertStatus(429);
});

it('broadcasts to ride channel when driver has active ride', function () {
    Event::fake([DriverLocationUpdated::class]);

    $passenger = User::factory()->create(['type' => UserType::Passenger]);

    $ride = Ride::factory()->create([
        'passenger_id' => $passenger->id,
        'driver_id' => $this->driverUser->id,
        'status' => RideStatus::DriverEnRoute,
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/driver/location', [
            'lat' => 9.0579,
            'lng' => 7.4951,
        ]);

    Event::assertDispatched(DriverLocationUpdated::class, function ($event) use ($ride) {
        return $event->rideId === $ride->id;
    });
});

it('does not include ride channel when no active ride', function () {
    Event::fake([DriverLocationUpdated::class]);

    $this->withToken($this->token)
        ->postJson('/api/v1/driver/location', [
            'lat' => 9.0579,
            'lng' => 7.4951,
        ]);

    Event::assertDispatched(DriverLocationUpdated::class, function ($event) {
        return $event->rideId === null;
    });
});

it('removes driver from geo set when going offline', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/driver/toggle-online')
        ->assertOk();

    $this->mockLocationService->shouldHaveReceived('removeDriver')
        ->once()
        ->with($this->driver->id);
});
