<?php

use App\Enums\AdminRole;
use App\Enums\DisputeOutcome;
use App\Enums\DriverStatus;
use App\Enums\SanctionTier;
use App\Enums\UserType;
use App\Jobs\AnalyzeOfflineTripJob;
use App\Jobs\MonitorCancellationJob;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\Ride;
use App\Models\User;
use App\Services\OfflineTripDetectionService;
use App\Services\SanctionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->create([
        'user_id' => $this->driverUser->id,
        'status' => DriverStatus::Approved,
        'is_online' => true,
    ]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->admin = User::factory()->create([
        'type' => UserType::Admin,
        'admin_role' => AdminRole::Operations,
    ]);
    $this->adminToken = $this->admin->createToken('auth', ['admin', 'operations'])->plainTextToken;

    $this->ride = Ride::factory()->cancelled()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
    ]);
});

describe('OfflineTripDetectionService', function () {
    it('flags collocation when trajectory matches intended route', function () {
        $service = app(OfflineTripDetectionService::class);

        $intendedRoute = [
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 9.0765,
            'destination_lng' => 7.4898,
        ];

        $trajectory = [];
        for ($i = 0; $i <= 5; $i++) {
            $fraction = $i / 5;
            $trajectory[] = [
                'lat' => $intendedRoute['pickup_lat'] + ($intendedRoute['destination_lat'] - $intendedRoute['pickup_lat']) * $fraction,
                'lng' => $intendedRoute['pickup_lng'] + ($intendedRoute['destination_lng'] - $intendedRoute['pickup_lng']) * $fraction,
                'timestamp' => now()->addSeconds($i * 30)->timestamp,
            ];
        }

        $result = $service->analyzeCollocation($trajectory, $intendedRoute);

        expect($result['flagged'])->toBeTrue()
            ->and($result['collocation_points'])->toBe(6)
            ->and($result['route_match_percentage'])->toBe(100.0);
    });

    it('does not flag when trajectory diverges from route', function () {
        $service = app(OfflineTripDetectionService::class);

        $intendedRoute = [
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 9.0765,
            'destination_lng' => 7.4898,
        ];

        $trajectory = [
            ['lat' => 9.1000, 'lng' => 7.6000, 'timestamp' => now()->timestamp],
            ['lat' => 9.1200, 'lng' => 7.6200, 'timestamp' => now()->addSeconds(30)->timestamp],
            ['lat' => 9.1400, 'lng' => 7.6400, 'timestamp' => now()->addSeconds(60)->timestamp],
            ['lat' => 9.1600, 'lng' => 7.6600, 'timestamp' => now()->addSeconds(90)->timestamp],
        ];

        $result = $service->analyzeCollocation($trajectory, $intendedRoute);

        expect($result['flagged'])->toBeFalse();
    });

    it('returns not flagged for empty trajectory', function () {
        $service = app(OfflineTripDetectionService::class);

        $result = $service->analyzeCollocation([], [
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 9.0765,
            'destination_lng' => 7.4898,
        ]);

        expect($result['flagged'])->toBeFalse()
            ->and($result['reason'])->toBe('no_trajectory_data');
    });
});

describe('SanctionService', function () {
    it('assigns L1 warning for first offence', function () {
        $sanctionService = app(SanctionService::class);
        $tier = $sanctionService->determineTier($this->driverUser->id);

        expect($tier)->toBe(SanctionTier::Warning);
    });

    it('assigns L2 suspension for second offence', function () {
        OfflineTripFlag::factory()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => Ride::factory()->cancelled()->create(['driver_id' => $this->driverUser->id]),
            'flagged_at' => now()->subDays(5),
        ]);

        $sanctionService = app(SanctionService::class);
        $tier = $sanctionService->determineTier($this->driverUser->id);

        expect($tier)->toBe(SanctionTier::Suspension);
    });

    it('assigns L3 deactivation for third offence', function () {
        OfflineTripFlag::factory()->count(2)->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => Ride::factory()->cancelled()->create(['driver_id' => $this->driverUser->id]),
            'flagged_at' => now()->subDays(5),
        ]);

        $sanctionService = app(SanctionService::class);
        $tier = $sanctionService->determineTier($this->driverUser->id);

        expect($tier)->toBe(SanctionTier::Deactivation);
    });

    it('excludes overturned flags from tier calculation', function () {
        OfflineTripFlag::factory()->overturned()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => Ride::factory()->cancelled()->create(['driver_id' => $this->driverUser->id]),
        ]);

        $sanctionService = app(SanctionService::class);
        $tier = $sanctionService->determineTier($this->driverUser->id);

        expect($tier)->toBe(SanctionTier::Warning);
    });

    it('creates flag and applies sanction for a ride', function () {
        $sanctionService = app(SanctionService::class);
        $flag = $sanctionService->applyForRide($this->ride, [
            'collocation_points' => 5,
            'route_match_percentage' => 80.0,
        ]);

        expect($flag->sanction_tier)->toBe(SanctionTier::Warning)
            ->and($flag->ride_id)->toBe($this->ride->id)
            ->and($flag->driver_id)->toBe($this->driverUser->id);

        $this->assertDatabaseHas('offline_trip_flags', [
            'ride_id' => $this->ride->id,
            'driver_id' => $this->driverUser->id,
            'sanction_tier' => SanctionTier::Warning->value,
        ]);
    });
});

describe('AnalyzeOfflineTripJob', function () {
    it('flags ride when collocation detected', function () {
        $intendedRoute = [
            'pickup_lat' => (float) $this->ride->pickup_lat,
            'pickup_lng' => (float) $this->ride->pickup_lng,
            'destination_lat' => (float) $this->ride->destination_lat,
            'destination_lng' => (float) $this->ride->destination_lng,
        ];

        $trajectory = [];
        for ($i = 0; $i <= 5; $i++) {
            $fraction = $i / 5;
            $trajectory[] = [
                'lat' => $intendedRoute['pickup_lat'] + ($intendedRoute['destination_lat'] - $intendedRoute['pickup_lat']) * $fraction,
                'lng' => $intendedRoute['pickup_lng'] + ($intendedRoute['destination_lng'] - $intendedRoute['pickup_lng']) * $fraction,
                'timestamp' => now()->addSeconds($i * 30)->timestamp,
            ];
        }

        Cache::put("offline_monitoring:{$this->ride->id}", $trajectory, 3600);

        $job = new AnalyzeOfflineTripJob($this->ride->id, $this->driverUser->id);
        $job->handle(app(OfflineTripDetectionService::class), app(SanctionService::class));

        $this->assertDatabaseHas('offline_trip_flags', [
            'ride_id' => $this->ride->id,
            'driver_id' => $this->driverUser->id,
        ]);

        expect(Cache::has("offline_monitoring:{$this->ride->id}"))->toBeFalse();
    });
});

describe('Driver flag dispute — POST /driver/flags/{flag}/dispute', function () {
    it('allows driver to dispute a flag', function () {
        $flag = OfflineTripFlag::factory()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => $this->ride->id,
        ]);

        $response = $this->postJson(
            "/api/v1/driver/flags/{$flag->id}/dispute",
            ['notes' => 'I did not complete this ride offline. The passenger cancelled and I went home.'],
            ['Authorization' => "Bearer {$this->driverToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('flag.is_disputed', true);

        $this->assertDatabaseHas('offline_trip_flags', [
            'id' => $flag->id,
            'is_disputed' => true,
            'dispute_outcome' => DisputeOutcome::Pending->value,
        ]);
    });

    it('rejects dispute on already disputed flag', function () {
        $flag = OfflineTripFlag::factory()->disputed()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => $this->ride->id,
        ]);

        $response = $this->postJson(
            "/api/v1/driver/flags/{$flag->id}/dispute",
            ['notes' => 'Disputing again.'],
            ['Authorization' => "Bearer {$this->driverToken}"],
        );

        $response->assertStatus(422);
    });

    it('rejects dispute from wrong driver', function () {
        $otherDriver = User::factory()->create(['type' => UserType::Driver]);
        $otherToken = $otherDriver->createToken('auth', ['driver'])->plainTextToken;

        $flag = OfflineTripFlag::factory()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => $this->ride->id,
        ]);

        $response = $this->postJson(
            "/api/v1/driver/flags/{$flag->id}/dispute",
            ['notes' => 'I should not be able to dispute this.'],
            ['Authorization' => "Bearer {$otherToken}"],
        );

        $response->assertStatus(422);
    });
});

describe('Driver compliance — GET /driver/compliance', function () {
    it('returns compliance status for driver', function () {
        OfflineTripFlag::factory()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => $this->ride->id,
        ]);

        $response = $this->getJson(
            '/api/v1/driver/compliance',
            ['Authorization' => "Bearer {$this->driverToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('compliance.active_flags_count', 1)
            ->assertJsonPath('compliance.total_flags_count', 1);
    });

    it('returns empty compliance for clean driver', function () {
        $response = $this->getJson(
            '/api/v1/driver/compliance',
            ['Authorization' => "Bearer {$this->driverToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('compliance.active_flags_count', 0)
            ->assertJsonPath('compliance.active_sanction', null);
    });
});

describe('Admin offline flags — GET /admin/offline-flags', function () {
    it('lists flagged trips', function () {
        OfflineTripFlag::factory()->count(3)->create([
            'driver_id' => $this->driverUser->id,
        ]);

        $response = $this->getJson(
            '/api/v1/admin/offline-flags',
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonCount(3, 'flags');
    });

    it('filters by sanction tier', function () {
        OfflineTripFlag::factory()->create(['driver_id' => $this->driverUser->id]);
        OfflineTripFlag::factory()->tier2()->create(['driver_id' => $this->driverUser->id]);

        $response = $this->getJson(
            '/api/v1/admin/offline-flags?sanction_tier=2',
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonCount(1, 'flags');
    });

    it('filters by disputed status', function () {
        OfflineTripFlag::factory()->create(['driver_id' => $this->driverUser->id]);
        OfflineTripFlag::factory()->disputed()->create(['driver_id' => $this->driverUser->id]);

        $response = $this->getJson(
            '/api/v1/admin/offline-flags?is_disputed=true',
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonCount(1, 'flags');
    });
});

describe('Admin review — POST /admin/offline-flags/{flag}/review', function () {
    it('allows admin to uphold a dispute', function () {
        $flag = OfflineTripFlag::factory()->disputed()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => $this->ride->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/offline-flags/{$flag->id}/review",
            ['outcome' => 'upheld', 'notes' => 'Evidence confirms offline trip activity.'],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('message', 'Dispute Upheld.');

        $this->assertDatabaseHas('offline_trip_flags', [
            'id' => $flag->id,
            'dispute_outcome' => 'upheld',
        ]);
    });

    it('allows admin to overturn a dispute and reverses sanction', function () {
        $flag = OfflineTripFlag::factory()->disputed()->tier2()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => $this->ride->id,
        ]);

        $this->driver->update(['status' => DriverStatus::Suspended, 'suspended_at' => now()]);

        $response = $this->postJson(
            "/api/v1/admin/offline-flags/{$flag->id}/review",
            ['outcome' => 'overturned', 'notes' => 'False positive — GPS drift confirmed.'],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('message', 'Dispute Overturned.');

        $this->assertDatabaseHas('offline_trip_flags', [
            'id' => $flag->id,
            'dispute_outcome' => 'overturned',
            'dispute_resolved_by_admin_id' => $this->admin->id,
        ]);

        $this->driver->refresh();
        expect($this->driver->status)->toBe(DriverStatus::Approved);
    });

    it('rejects review for non-disputed flag', function () {
        $flag = OfflineTripFlag::factory()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => $this->ride->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/offline-flags/{$flag->id}/review",
            ['outcome' => 'upheld', 'notes' => 'Should not work.'],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertStatus(422);
    });
});

describe('Admin escalation — POST /admin/offline-flags/{flag}/escalate', function () {
    it('escalates sanction tier', function () {
        $flag = OfflineTripFlag::factory()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => $this->ride->id,
            'sanction_tier' => SanctionTier::Warning,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/offline-flags/{$flag->id}/escalate",
            ['notes' => 'Pattern of abuse confirmed.'],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('flag.sanction_tier.value', SanctionTier::Suspension->value);

        $this->assertDatabaseHas('offline_trip_flags', [
            'id' => $flag->id,
            'sanction_tier' => SanctionTier::Suspension->value,
        ]);
    });

    it('rejects escalation beyond tier 3', function () {
        $flag = OfflineTripFlag::factory()->tier3()->create([
            'driver_id' => $this->driverUser->id,
            'ride_id' => $this->ride->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/offline-flags/{$flag->id}/escalate",
            [],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertStatus(422);
    });
});
