<?php

use App\Contracts\PushNotificationGateway;
use App\Enums\AdminRole;
use App\Enums\DisputeOutcome;
use App\Enums\DriverStatus;
use App\Enums\SanctionTier;
use App\Jobs\AnalyzeOfflineTripJob;
use App\Jobs\MonitorCancellationJob;
use App\Models\City;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\Ride;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Services\OfflineTripDetectionService;
use App\Services\SanctionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->city = City::factory()->create(['name' => 'Abuja']);
    $this->driverUser = User::factory()->driver()->create(['first_name' => 'Tunde', 'last_name' => 'Bello', 'phone' => '+2348099990000']);
    $this->driver = Driver::factory()->create([
        'user_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'status' => DriverStatus::Approved,
    ]);
    $this->ride = Ride::factory()->cancelled()->create(['driver_id' => $this->driverUser->id]);

    $this->admin = User::factory()->admin(AdminRole::Operations)->create();
    $this->token = $this->admin->createToken('auth', ['admin'])->plainTextToken;

    $push = Mockery::mock(PushNotificationGateway::class);
    $push->shouldReceive('sendToUser')->andReturnNull();
    $this->app->instance(PushNotificationGateway::class, $push);
});

function complianceFlag(array $attributes = []): OfflineTripFlag
{
    return OfflineTripFlag::factory()->create([
        'driver_id' => test()->driverUser->id,
        'ride_id' => test()->ride->id,
        ...$attributes,
    ]);
}

it('gives each flag a readable reference and a status', function () {
    $flag = complianceFlag();

    expect($flag->reference_number)->toMatch('/^FLG-\d{6}$/');

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/offline-flags/{$flag->id}")
        ->assertJsonPath('flag.reference_number', $flag->reference_number)
        ->assertJsonPath('flag.status.value', 'flagged');
});

it('filters the queue by status', function (string $status) {
    $flags = [
        'flagged' => complianceFlag(),
        'disputed' => complianceFlag(['is_disputed' => true, 'dispute_outcome' => DisputeOutcome::Pending]),
        'upheld' => complianceFlag(['dispute_outcome' => DisputeOutcome::Upheld, 'resolved_at' => now()]),
        'overturned' => complianceFlag(['is_disputed' => true, 'dispute_outcome' => DisputeOutcome::Overturned, 'resolved_at' => now()]),
    ];

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/offline-flags?status={$status}")
        ->assertOk()
        ->assertJsonCount(1, 'flags')
        ->assertJsonPath('flags.0.id', $flags[$status]->id)
        ->assertJsonPath('flags.0.status.value', $status);
})->with(['flagged', 'disputed', 'upheld', 'overturned']);

it('searches by flag reference, driver name, phone and driver ID', function () {
    $flag = complianceFlag();
    OfflineTripFlag::factory()->create();

    foreach ([$flag->reference_number, 'tunde bello', '9990000', $this->driver->fresh()->reference_number] as $term) {
        $this->withToken($this->token)
            ->getJson('/api/v1/admin/offline-flags?search='.urlencode($term))
            ->assertOk()
            ->assertJsonCount(1, 'flags')
            ->assertJsonPath('flags.0.id', $flag->id);
    }
});

it('explains why the trip was flagged and shows the evidence', function () {
    $flag = complianceFlag(['detection_data' => [
        'collocation_points' => 14,
        'total_trajectory_points' => 16,
        'route_match_percentage' => 87.5,
        'threshold_meters' => 200,
        'on_route_duration_seconds' => 650,
        'average_gps_accuracy_meters' => 15.0,
        'cancel_distance_from_pickup_meters' => 300.0,
    ]]);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/offline-flags/{$flag->id}")
        ->assertJsonPath('flag.evidence.route_match_percentage', 87.5)
        ->assertJsonPath('flag.evidence.cancel_distance_from_pickup_km', 0.3)
        ->assertJsonPath('flag.evidence.on_route_duration_seconds', 650)
        ->assertJsonPath('flag.evidence.average_gps_accuracy_meters', 15)
        ->assertJsonPath('flag.detection_summary', "The ride was cancelled with the driver 0.3 km from the pickup. Afterwards, the driver's phone followed the passenger's route: 87.5% of its location samples (14 of 16) were within 200 m of it, over 10 min 50 s.");
});

it('leaves figures an older flag never recorded as null', function () {
    $flag = complianceFlag(['detection_data' => ['route_match_percentage' => 80, 'collocation_points' => 5]]);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/offline-flags/{$flag->id}")
        ->assertJsonPath('flag.evidence.cancel_distance_from_pickup_km', null)
        ->assertJsonPath('flag.evidence.average_gps_accuracy_meters', null)
        ->assertJsonPath('flag.detection_summary', "After the ride was cancelled, the driver's phone followed the passenger's route: 80% of its location samples were on it.");
});

it("returns the driver's city, the dispute and earlier flags", function () {
    $earlier = complianceFlag(['flagged_at' => now()->subDays(10)]);
    complianceFlag(['flagged_at' => now()->subDays(45)]);

    $flag = complianceFlag(['sanction_tier' => SanctionTier::Suspension]);
    $this->withToken($this->driverUser->createToken('auth', ['driver'])->plainTextToken)
        ->postJson("/api/v1/driver/flags/{$flag->id}/dispute", ['notes' => 'I was dropping my own family at home.'])
        ->assertOk();

    // The test client keeps the driver signed in otherwise.
    $this->app['auth']->forgetGuards();

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/admin/offline-flags/{$flag->id}")
        ->assertOk()
        ->assertJsonPath('flag.driver_city.name', 'Abuja')
        ->assertJsonPath('flag.status.value', 'disputed')
        ->assertJsonPath('flag.dispute.notes', 'I was dropping my own family at home.')
        ->assertJsonPath('flag.dispute.channel', 'driver_app')
        ->assertJsonPath('previous_flags_window_days', 30)
        ->assertJsonCount(1, 'previous_flags')
        ->assertJsonPath('previous_flags.0.id', $earlier->id);

    expect($response->json('flag.dispute.filed_at'))->not->toBeNull()
        ->and(collect($response->json('history'))->pluck('event')->all())->toContain('offline_flag_disputed');
});

it('upholds a flag the driver never disputed and keeps the reason', function () {
    $flag = complianceFlag();

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/review", ['outcome' => 'upheld', 'notes' => 'Trajectory matches the route end to end.'])
        ->assertOk()
        ->assertJsonPath('flag.status.value', 'upheld')
        ->assertJsonPath('flag.review_notes', 'Trajectory matches the route end to end.');

    $history = $this->withToken($this->token)->getJson("/api/v1/admin/offline-flags/{$flag->id}")->json('history');
    expect(end($history))->toMatchArray([
        'event' => 'offline_flag_reviewed',
        'description' => 'Upheld',
        'notes' => 'Trajectory matches the route end to end.',
    ])->and(end($history)['actor']['role'])->toBe('operations');
});

it('stops an overturned flag counting towards the next sanction, disputed or not', function () {
    complianceFlag(['dispute_outcome' => DisputeOutcome::Overturned, 'resolved_at' => now()]);

    expect(app(SanctionService::class)->determineTier($this->driverUser->id))->toBe(SanctionTier::Warning);
});

it('requires a reason to escalate', function () {
    $flag = complianceFlag();

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/escalate", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notes');
});

it('de-escalates level 3 to level 2 and keeps the driver suspended', function () {
    $this->driver->update([
        'status' => DriverStatus::Suspended,
        'suspended_at' => now()->subHours(3),
        'suspension_reason' => SanctionService::DEACTIVATION_REVIEW_REASON,
    ]);
    $flag = complianceFlag(['sanction_tier' => SanctionTier::Deactivation]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/de-escalate", ['notes' => 'First offence pattern, reduce to suspension.'])
        ->assertOk()
        ->assertJsonPath('flag.sanction_tier.value', 2)
        ->assertJsonPath('flag.sanction_action', 'suspended_48h');

    $driver = $this->driver->fresh();
    expect($driver->status)->toBe(DriverStatus::Suspended)
        ->and($driver->suspension_reason)->toBe(SanctionService::AUTOMATIC_SUSPENSION_REASON);

    $history = $this->withToken($this->token)->getJson("/api/v1/admin/offline-flags/{$flag->id}")->json('history');
    expect(end($history)['description'])->toBe('De-escalated from Level 3 to Level 2')
        ->and(end($history)['notes'])->toBe('First offence pattern, reduce to suspension.');
});

it('de-escalates level 2 to a warning and reinstates the driver', function () {
    $this->driver->update([
        'status' => DriverStatus::Suspended,
        'suspended_at' => now()->subHour(),
        'suspension_reason' => SanctionService::AUTOMATIC_SUSPENSION_REASON,
    ]);
    $flag = complianceFlag(['sanction_tier' => SanctionTier::Suspension]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/de-escalate", ['notes' => 'Driver explained the detour convincingly.'])
        ->assertOk()
        ->assertJsonPath('flag.sanction_tier.value', 1);

    expect($this->driver->fresh()->status)->toBe(DriverStatus::Approved);
});

it('leaves a suspension an admin made by hand alone', function () {
    $this->driver->update([
        'status' => DriverStatus::Suspended,
        'suspended_at' => now(),
        'suspension_reason' => 'Documents expired.',
    ]);
    $flag = complianceFlag(['sanction_tier' => SanctionTier::Suspension]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/de-escalate", ['notes' => 'Reduce this flag to a warning.'])
        ->assertOk();

    expect($this->driver->fresh()->status)->toBe(DriverStatus::Suspended);
});

it('will not de-escalate a warning or a deactivated driver', function () {
    $warning = complianceFlag();
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$warning->id}/de-escalate", ['notes' => 'Nothing below a warning.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['flag' => 'This flag is already at the lowest sanction tier.']);

    $this->driver->update(['status' => DriverStatus::Deactivated]);
    $deactivation = complianceFlag(['sanction_tier' => SanctionTier::Deactivation]);
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$deactivation->id}/de-escalate", ['notes' => 'Trying to bring them back.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('flag');
});

it('records where the driver was when the ride was cancelled', function () {
    Queue::fake();
    $ride = Ride::factory()->cancelled()->create([
        'driver_id' => $this->driverUser->id,
        'pickup_lat' => 6.5000,
        'pickup_lng' => 3.4000,
    ]);

    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('getDriverLocation')->andReturn([
        'lat' => 6.5010, 'lng' => 3.4000, 'heading' => 0.0, 'speed' => 0.0, 'accuracy' => 12.0, 'timestamp' => now()->timestamp,
    ]);
    $this->app->instance(DriverLocationService::class, $locations);

    expect(app(OfflineTripDetectionService::class)->startMonitoringAfterCancellation($ride, $this->driverUser->id))->toBeTrue();

    Queue::assertPushed(MonitorCancellationJob::class);

    $context = Cache::get(OfflineTripDetectionService::CONTEXT_CACHE_PREFIX.$ride->id);
    expect($context['cancel_distance_from_pickup_meters'])->toBeGreaterThan(100)->toBeLessThan(120)
        ->and($context['cancel_gps_accuracy_meters'])->toBe(12.0);
});

it('adds the cancellation context, time on route and GPS accuracy to a new flag', function () {
    $ride = Ride::factory()->cancelled()->create([
        'driver_id' => $this->driverUser->id,
        'pickup_lat' => 6.5000, 'pickup_lng' => 3.4000,
        'destination_lat' => 6.5500, 'destination_lng' => 3.4500,
    ]);

    $trajectory = [];
    for ($i = 0; $i <= 5; $i++) {
        $trajectory[] = [
            'lat' => 6.5 + 0.05 * $i / 5,
            'lng' => 3.4 + 0.05 * $i / 5,
            'timestamp' => now()->addSeconds($i * 30)->timestamp,
            'accuracy' => $i % 2 ? 10.0 : 20.0,
        ];
    }

    Cache::put("offline_monitoring:{$ride->id}", $trajectory, 3600);
    Cache::put(OfflineTripDetectionService::CONTEXT_CACHE_PREFIX.$ride->id, ['cancel_distance_from_pickup_meters' => 250.0], 3600);

    (new AnalyzeOfflineTripJob($ride->id, $this->driverUser->id))
        ->handle(app(OfflineTripDetectionService::class), app(SanctionService::class));

    $flag = OfflineTripFlag::where('ride_id', $ride->id)->firstOrFail();

    expect($flag->detection_data)->toMatchArray([
        'cancel_distance_from_pickup_meters' => 250,
        'on_route_duration_seconds' => 150,
        'average_gps_accuracy_meters' => 15,
    ])->and(Cache::has(OfflineTripDetectionService::CONTEXT_CACHE_PREFIX.$ride->id))->toBeFalse();
});
