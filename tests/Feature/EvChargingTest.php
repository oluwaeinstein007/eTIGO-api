<?php

use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Enums\EvStationStatus;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\EvChargingStation;
use App\Models\EvChargingStall;
use App\Models\EvReservation;
use App\Models\User;

beforeEach(function () {
    $this->city = City::factory()->create();

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->create([
        'user_id' => $this->driverUser->id,
        'status' => DriverStatus::Approved,
        'is_online' => true,
    ]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->admin = User::factory()->create([
        'type' => UserType::Admin,
        'admin_role' => AdminRole::Operations,
    ]);
    $this->adminToken = $this->admin->createToken('auth', ['admin', 'operations'])->plainTextToken;

    $this->station = EvChargingStation::factory()->create([
        'city_id' => $this->city->id,
        'total_stalls' => 4,
    ]);

    for ($i = 1; $i <= 4; $i++) {
        EvChargingStall::factory()->create([
            'station_id' => $this->station->id,
            'stall_number' => $i,
        ]);
    }
});

// ── Public Endpoints ──

describe('Public EV Station Listing', function () {
    it('lists active stations for a city', function () {
        EvChargingStation::factory()->inactive()->create(['city_id' => $this->city->id]);

        $response = $this->getJson("/api/v1/ev-stations?city_id={$this->city->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'stations')
            ->assertJsonPath('stations.0.id', $this->station->id);
    });

    it('requires city_id parameter', function () {
        $this->getJson('/api/v1/ev-stations')->assertUnprocessable();
    });

    it('shows station details', function () {
        $response = $this->getJson("/api/v1/ev-stations/{$this->station->id}");

        $response->assertOk()
            ->assertJsonPath('station.id', $this->station->id)
            ->assertJsonPath('station.name', $this->station->name);
    });
});

// ── Admin Station Management ──

describe('Admin EV Station CRUD', function () {
    it('creates a station with auto-generated stalls', function () {
        $response = $this->withToken($this->adminToken)->postJson('/api/v1/admin/ev-stations', [
            'name' => 'Test Charging Hub',
            'city_id' => $this->city->id,
            'lat' => 9.0579,
            'lng' => 7.4951,
            'address' => '123 Test Street, Abuja',
            'total_stalls' => 6,
        ]);

        $response->assertCreated()
            ->assertJsonPath('station.name', 'Test Charging Hub')
            ->assertJsonPath('station.total_stalls', 6);

        $stationId = $response->json('station.id');
        expect(EvChargingStall::where('station_id', $stationId)->count())->toBe(6);
    });

    it('lists stations with filters', function () {
        $response = $this->withToken($this->adminToken)
            ->getJson("/api/v1/admin/ev-stations?city_id={$this->city->id}");

        $response->assertOk()
            ->assertJsonStructure(['stations', 'meta']);
    });

    it('shows station details with stall availability', function () {
        $response = $this->withToken($this->adminToken)
            ->getJson("/api/v1/admin/ev-stations/{$this->station->id}");

        $response->assertOk()
            ->assertJsonStructure(['station', 'availability']);
    });

    it('updates a station', function () {
        $response = $this->withToken($this->adminToken)
            ->putJson("/api/v1/admin/ev-stations/{$this->station->id}", [
                'name' => 'Updated Hub Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('station.name', 'Updated Hub Name');
    });

    it('toggles station status', function () {
        $response = $this->withToken($this->adminToken)
            ->patchJson("/api/v1/admin/ev-stations/{$this->station->id}/status", [
                'status' => 'maintenance',
            ]);

        $response->assertOk()
            ->assertJsonPath('station.status.value', 'maintenance');
    });

    it('returns utilisation summary', function () {
        $response = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/ev-stations/utilisation');

        $response->assertOk()
            ->assertJsonStructure(['utilisation', 'summary']);
    });

    it('rejects non-admin access', function () {
        $this->withToken($this->driverToken)
            ->getJson('/api/v1/admin/ev-stations')
            ->assertForbidden();
    });
});

// ── Driver Reservation Flow ──

describe('EV Reservation - Immediate Lock', function () {
    it('reserves an available stall immediately', function () {
        $response = $this->withToken($this->driverToken)
            ->postJson("/api/v1/driver/ev-reservations/stations/{$this->station->id}/reserve");

        $response->assertCreated()
            ->assertJsonPath('outcome', 'reserved')
            ->assertJsonStructure(['reservation' => ['id', 'station_id', 'stall_id', 'status']]);

        $reservationId = $response->json('reservation.id');
        $reservation = EvReservation::find($reservationId);

        expect($reservation->status)->toBe(EvReservationStatus::Reserved)
            ->and($reservation->stall_id)->not->toBeNull();
    });

    it('prevents duplicate active reservations', function () {
        $this->withToken($this->driverToken)
            ->postJson("/api/v1/driver/ev-reservations/stations/{$this->station->id}/reserve")
            ->assertCreated();

        $this->withToken($this->driverToken)
            ->postJson("/api/v1/driver/ev-reservations/stations/{$this->station->id}/reserve")
            ->assertUnprocessable();
    });

    it('rejects reservation on inactive station', function () {
        $this->station->update(['status' => EvStationStatus::Maintenance]);

        $this->withToken($this->driverToken)
            ->postJson("/api/v1/driver/ev-reservations/stations/{$this->station->id}/reserve")
            ->assertUnprocessable();
    });
});

describe('EV Reservation - Queue Hold', function () {
    it('queues driver when stalls occupied but departure imminent', function () {
        $this->station->stalls()->update([
            'status' => EvStallStatus::Occupied,
            'current_vehicle_driver_id' => User::factory()->create(['type' => UserType::Driver])->id,
            'occupied_since' => now()->subMinutes(45),
            'estimated_departure_at' => now()->addMinutes(10),
        ]);

        $response = $this->withToken($this->driverToken)
            ->postJson("/api/v1/driver/ev-reservations/stations/{$this->station->id}/reserve");

        $response->assertOk()
            ->assertJsonPath('outcome', 'queued')
            ->assertJsonStructure(['queue_position', 'estimated_available_at']);
    });
});

describe('EV Reservation - Retry', function () {
    it('returns retry when no stalls available and no imminent departure', function () {
        $this->station->stalls()->update([
            'status' => EvStallStatus::Occupied,
            'current_vehicle_driver_id' => User::factory()->create(['type' => UserType::Driver])->id,
            'occupied_since' => now()->subMinutes(5),
            'estimated_departure_at' => now()->addMinutes(60),
        ]);

        $response = $this->withToken($this->driverToken)
            ->postJson("/api/v1/driver/ev-reservations/stations/{$this->station->id}/reserve");

        $response->assertOk()
            ->assertJsonPath('outcome', 'retry')
            ->assertJsonStructure(['retry_after_minutes', 'estimated_wait_minutes']);
    });
});

describe('EV Reservation - Lifecycle', function () {
    it('activates a reserved reservation', function () {
        $reservation = EvReservation::factory()->withStall()->create([
            'station_id' => $this->station->id,
            'driver_id' => $this->driverUser->id,
            'status' => EvReservationStatus::Reserved,
        ]);

        $response = $this->withToken($this->driverToken)
            ->postJson("/api/v1/driver/ev-reservations/{$reservation->id}/activate");

        $response->assertOk()
            ->assertJsonPath('reservation.status.value', 'active');
    });

    it('completes an active reservation', function () {
        $stall = $this->station->stalls->first();
        $reservation = EvReservation::factory()->create([
            'station_id' => $this->station->id,
            'stall_id' => $stall->id,
            'driver_id' => $this->driverUser->id,
            'status' => EvReservationStatus::Active,
            'activated_at' => now()->subHour(),
        ]);

        $response = $this->withToken($this->driverToken)
            ->postJson("/api/v1/driver/ev-reservations/{$reservation->id}/complete");

        $response->assertOk()
            ->assertJsonPath('reservation.status.value', 'completed');
    });

    it('cancels an active reservation', function () {
        $reservation = EvReservation::factory()->create([
            'station_id' => $this->station->id,
            'driver_id' => $this->driverUser->id,
            'status' => EvReservationStatus::Reserved,
        ]);

        $response = $this->withToken($this->driverToken)
            ->postJson("/api/v1/driver/ev-reservations/{$reservation->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('reservation.status.value', 'cancelled');
    });

    it('prevents cancelling already completed reservations', function () {
        $reservation = EvReservation::factory()->completed()->create([
            'station_id' => $this->station->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $this->withToken($this->driverToken)
            ->postJson("/api/v1/driver/ev-reservations/{$reservation->id}/cancel")
            ->assertUnprocessable();
    });

    it('prevents other drivers from managing a reservation', function () {
        $otherDriver = User::factory()->create(['type' => UserType::Driver]);
        Driver::factory()->create(['user_id' => $otherDriver->id, 'status' => DriverStatus::Approved]);
        $otherToken = $otherDriver->createToken('auth', ['driver'])->plainTextToken;

        $reservation = EvReservation::factory()->create([
            'station_id' => $this->station->id,
            'driver_id' => $this->driverUser->id,
            'status' => EvReservationStatus::Reserved,
        ]);

        $this->withToken($otherToken)
            ->postJson("/api/v1/driver/ev-reservations/{$reservation->id}/cancel")
            ->assertForbidden();
    });
});

describe('Driver Reservation Listing', function () {
    it('lists driver reservations', function () {
        EvReservation::factory()->count(3)->create([
            'station_id' => $this->station->id,
            'driver_id' => $this->driverUser->id,
            'status' => EvReservationStatus::Completed,
        ]);

        $response = $this->withToken($this->driverToken)
            ->getJson('/api/v1/driver/ev-reservations');

        $response->assertOk()
            ->assertJsonCount(3, 'reservations');
    });

    it('filters reservations by status', function () {
        EvReservation::factory()->create([
            'station_id' => $this->station->id,
            'driver_id' => $this->driverUser->id,
            'status' => EvReservationStatus::Completed,
        ]);
        EvReservation::factory()->create([
            'station_id' => $this->station->id,
            'driver_id' => $this->driverUser->id,
            'status' => EvReservationStatus::Cancelled,
        ]);

        $response = $this->withToken($this->driverToken)
            ->getJson('/api/v1/driver/ev-reservations?status=completed');

        $response->assertOk()
            ->assertJsonCount(1, 'reservations');
    });
});
