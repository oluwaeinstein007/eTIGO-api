<?php

use App\Contracts\PushNotificationGateway;
use App\Enums\AdminRole;
use App\Enums\DisputeOutcome;
use App\Enums\DriverStatus;
use App\Enums\SanctionTier;
use App\Jobs\AnalyzeOfflineTripJob;
use App\Jobs\MonitorCancellationJob;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\Ride;
use App\Models\User;
use App\Services\DriverLocationService;
use App\Services\OfflineTripDetectionService;
use App\Services\RideRouteService;
use App\Services\SanctionService;
use App\Support\Polyline;
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
        ->handle(app(OfflineTripDetectionService::class), app(SanctionService::class), app(RideRouteService::class));

    $flag = OfflineTripFlag::where('ride_id', $ride->id)->firstOrFail();

    expect($flag->detection_data)->toMatchArray([
        'cancel_distance_from_pickup_meters' => 250,
        'on_route_duration_seconds' => 150,
        'average_gps_accuracy_meters' => 15,
    ])->and(Cache::has(OfflineTripDetectionService::CONTEXT_CACHE_PREFIX.$ride->id))->toBeFalse();
});

function suspendForCompliance(string $reason, ?DateTimeInterface $since = null): void
{
    test()->driver->update([
        'status' => DriverStatus::Suspended,
        'suspended_at' => $since ?? now(),
        'suspension_reason' => $reason,
    ]);
}

// === Appeals: an upheld flag can be overturned, escalated or de-escalated ===

it('overturns an upheld flag on appeal and reinstates the driver', function () {
    suspendForCompliance(SanctionService::AUTOMATIC_SUSPENSION_REASON);
    $flag = complianceFlag(['sanction_tier' => SanctionTier::Suspension]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/review", ['outcome' => 'upheld', 'notes' => 'Route evidence is conclusive.'])
        ->assertOk()
        ->assertJsonPath('flag.allowed_actions.overturn', true)
        ->assertJsonPath('flag.allowed_actions.uphold', false);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/review", ['outcome' => 'overturned', 'notes' => 'Driver showed a fuel receipt from the other side of town.'])
        ->assertOk()
        ->assertJsonPath('flag.status.value', 'overturned')
        ->assertJsonPath('flag.review_notes', 'Driver showed a fuel receipt from the other side of town.');

    expect($this->driver->fresh()->status)->toBe(DriverStatus::Approved);

    $history = $this->withToken($this->token)->getJson("/api/v1/admin/offline-flags/{$flag->id}")->json('history');
    expect(array_column($history, 'description'))->toBe(['Upheld', 'Overturned on appeal']);
});

it('escalates and de-escalates an upheld flag but not an overturned one', function () {
    $upheld = complianceFlag(['dispute_outcome' => DisputeOutcome::Upheld, 'resolved_at' => now()]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$upheld->id}/escalate", ['notes' => 'Appeal review found a second trip.'])
        ->assertOk()
        ->assertJsonPath('flag.sanction_tier.value', 2)
        ->assertJsonPath('flag.status.value', 'upheld');

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$upheld->id}/de-escalate", ['notes' => 'Second trip was a mistake on our side.'])
        ->assertOk()
        ->assertJsonPath('flag.sanction_tier.value', 1);

    $overturned = complianceFlag(['sanction_tier' => SanctionTier::Suspension, 'dispute_outcome' => DisputeOutcome::Overturned, 'resolved_at' => now()]);

    foreach (['escalate', 'de-escalate'] as $action) {
        $this->withToken($this->token)
            ->postJson("/api/v1/admin/offline-flags/{$overturned->id}/{$action}", ['notes' => 'Trying to change an overturned flag.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['flag' => 'This flag was overturned and no longer counts against the driver.']);
    }
});

// === Overturning sets the driver's status from the flags that still count ===

it('reinstates the driver when the latest flag is overturned and the earlier suspension has run out', function () {
    complianceFlag(['sanction_tier' => SanctionTier::Suspension, 'flagged_at' => now()->subDays(10)]);
    $latest = complianceFlag(['sanction_tier' => SanctionTier::Deactivation, 'flagged_at' => now()->subHour()]);
    suspendForCompliance(SanctionService::DEACTIVATION_REVIEW_REASON, now()->subHour());

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$latest->id}/review", ['outcome' => 'overturned', 'notes' => 'GPS drift on a shared road.'])
        ->assertOk();

    $driver = $this->driver->fresh();
    expect($driver->status)->toBe(DriverStatus::Approved)
        ->and($driver->suspension_reason)->toBeNull();
});

it('keeps the driver suspended until 48 hours after a recent earlier level 2', function () {
    $earlier = complianceFlag(['sanction_tier' => SanctionTier::Suspension, 'flagged_at' => now()->subHours(20)]);
    $latest = complianceFlag(['sanction_tier' => SanctionTier::Deactivation, 'flagged_at' => now()->subHour()]);
    suspendForCompliance(SanctionService::DEACTIVATION_REVIEW_REASON, now()->subHour());

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$latest->id}/review", ['outcome' => 'overturned', 'notes' => 'GPS drift on a shared road.'])
        ->assertOk();

    $driver = $this->driver->fresh();
    expect($driver->status)->toBe(DriverStatus::Suspended)
        ->and($driver->suspension_reason)->toBe(SanctionService::AUTOMATIC_SUSPENSION_REASON)
        ->and($driver->suspended_at->timestamp)->toBe($earlier->flagged_at->timestamp);
});

it('leaves the driver alone when an older flag is overturned', function () {
    $older = complianceFlag(['sanction_tier' => SanctionTier::Suspension, 'flagged_at' => now()->subDays(3)]);
    complianceFlag(['sanction_tier' => SanctionTier::Deactivation, 'flagged_at' => now()->subHour()]);
    suspendForCompliance(SanctionService::DEACTIVATION_REVIEW_REASON, now()->subHour());

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$older->id}/review", ['outcome' => 'overturned', 'notes' => 'That one was GPS drift.'])
        ->assertOk();

    expect($this->driver->fresh()->suspension_reason)->toBe(SanctionService::DEACTIVATION_REVIEW_REASON);
});

// === Confirming a deactivation ===

it('requires a reason to confirm a deactivation', function () {
    $flag = complianceFlag(['sanction_tier' => SanctionTier::Deactivation]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation", ['notes' => 'too short'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notes');

    expect($this->driver->fresh()->status)->toBe(DriverStatus::Approved);
});

it('upholds the flag, records who confirmed the deactivation and why, and shows it on the flag', function () {
    suspendForCompliance(SanctionService::DEACTIVATION_REVIEW_REASON);
    $flag = complianceFlag(['sanction_tier' => SanctionTier::Deactivation]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation", ['notes' => 'Third off-app trip in a month; passenger confirmed cash.'])
        ->assertOk()
        ->assertJsonPath('flag.status.value', 'upheld')
        ->assertJsonPath('flag.sanction_action', 'deactivated')
        ->assertJsonPath('flag.review_notes', 'Third off-app trip in a month; passenger confirmed cash.')
        ->assertJsonPath('flag.deactivation.confirmed_by.id', $this->admin->id)
        ->assertJsonPath('flag.deactivation.notes', 'Third off-app trip in a month; passenger confirmed cash.')
        ->assertJsonPath('flag.deactivation.reversed_at', null)
        ->assertJsonPath('flag.driver_status', 'deactivated')
        ->assertJsonPath('flag.allowed_actions', [
            'uphold' => false,
            'overturn' => false,
            'escalate' => false,
            'de_escalate' => false,
            'confirm_deactivation' => false,
            'verify_cash_taking' => false,
        ]);

    expect($flag->fresh()->resolved_at)->not->toBeNull()
        ->and($this->driver->fresh()->status)->toBe(DriverStatus::Deactivated);

    $last = collect($this->withToken($this->token)->getJson("/api/v1/admin/offline-flags/{$flag->id}")->json('history'))->last();
    expect($last['description'])->toBe('Upheld and deactivation confirmed')
        ->and($last['notes'])->toBe('Third off-app trip in a month; passenger confirmed cash.');

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation", ['notes' => 'Confirming a second time.'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Deactivation has already been confirmed for this flag.');
});

it('keeps the earlier review reason when the flag was already upheld', function () {
    $flag = complianceFlag([
        'sanction_tier' => SanctionTier::Deactivation,
        'dispute_outcome' => DisputeOutcome::Upheld,
        'review_notes' => 'Upheld after calling the passenger.',
        'resolved_at' => now()->subHour(),
    ]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation", ['notes' => 'Deactivating after the uphold.'])
        ->assertOk()
        ->assertJsonPath('flag.review_notes', 'Upheld after calling the passenger.')
        ->assertJsonPath('flag.deactivation.notes', 'Deactivating after the uphold.');
});

it('lets only a super admin undo a confirmed deactivation, by overturning the flag', function () {
    $flag = complianceFlag(['sanction_tier' => SanctionTier::Deactivation, 'flagged_at' => now()->subDay()]);
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation", ['notes' => 'Third off-app trip this month.'])
        ->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/de-escalate", ['notes' => 'Reduce to a suspension instead.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['flag' => "The driver's deactivation was confirmed. To undo it, a super admin can overturn the flag on appeal."]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/review", ['outcome' => 'overturned', 'notes' => 'Appeal: the passenger was his wife.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['flag' => 'Only a super admin can overturn a flag whose deactivation was confirmed.']);

    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();
    app('auth')->forgetGuards();

    $this->withToken($superAdmin->createToken('auth', ['admin'])->plainTextToken)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/review", ['outcome' => 'overturned', 'notes' => 'Appeal: the passenger was his wife.'])
        ->assertOk()
        ->assertJsonPath('flag.status.value', 'overturned')
        ->assertJsonPath('flag.driver_status', 'approved')
        ->assertJsonPath('flag.deactivation.confirmed_by.id', $this->admin->id);

    expect($flag->fresh()->deactivation_reversed_at)->not->toBeNull()
        ->and($this->driver->fresh()->status)->toBe(DriverStatus::Approved)
        ->and(AuditLog::where('event', 'compliance_deactivation_reversed')->where('auditable_id', $this->driver->id)->exists())->toBeTrue();
});

it('still shows a deactivated driver their sanction after the flag is upheld', function () {
    $flag = complianceFlag(['sanction_tier' => SanctionTier::Deactivation, 'flagged_at' => now()->subDays(40)]);
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation", ['notes' => 'Third off-app trip this month.'])
        ->assertOk();

    app('auth')->forgetGuards();

    $this->withToken($this->driverUser->createToken('auth')->plainTextToken)
        ->getJson('/api/v1/driver/compliance')
        ->assertOk()
        ->assertJsonPath('compliance.active_sanction.flag_id', $flag->id)
        ->assertJsonPath('compliance.active_sanction.status.value', 'upheld')
        ->assertJsonPath('compliance.active_sanction.deactivation_confirmed', true)
        ->assertJsonPath('recent_flags.0.deactivation.reversed_at', null)
        ->assertJsonMissingPath('recent_flags.0.deactivation.notes')
        ->assertJsonMissingPath('recent_flags.0.allowed_actions');
});

// === Verified cash-taking ===

it('moves a flag straight to level 3 when cash-taking is verified', function () {
    $flag = complianceFlag();

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/verify-cash-taking", ['notes' => 'Passenger confirmed by phone they paid ₦6,000 cash.'])
        ->assertOk()
        ->assertJsonPath('message', 'Cash-taking verified. The flag is now Level 3; confirm the deactivation to make it permanent.')
        ->assertJsonPath('flag.sanction_tier.value', 3)
        ->assertJsonPath('flag.sanction_action', 'pending_admin_review')
        ->assertJsonPath('flag.cash_taking.verified_by.id', $this->admin->id)
        ->assertJsonPath('flag.cash_taking.notes', 'Passenger confirmed by phone they paid ₦6,000 cash.')
        ->assertJsonPath('flag.allowed_actions.verify_cash_taking', false)
        // The driver first gets 48 hours to respond.
        ->assertJsonPath('flag.allowed_actions.confirm_deactivation', false);

    $driver = $this->driver->fresh();
    expect($driver->status)->toBe(DriverStatus::Suspended)
        ->and($driver->suspension_reason)->toBe(SanctionService::DEACTIVATION_REVIEW_REASON);

    $last = collect($this->withToken($this->token)->getJson("/api/v1/admin/offline-flags/{$flag->id}")->json('history'))->last();
    expect($last['description'])->toBe('Cash-taking verified from Level 1 to Level 3');

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/verify-cash-taking", ['notes' => 'Verifying it a second time.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['flag' => 'Cash-taking has already been verified on this flag.']);
});

it('will not verify cash-taking on an overturned flag', function () {
    $flag = complianceFlag(['dispute_outcome' => DisputeOutcome::Overturned, 'resolved_at' => now()]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/verify-cash-taking", ['notes' => 'Passenger now says they paid cash.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('flag');

    expect($flag->fresh()->sanction_tier)->toBe(SanctionTier::Warning);
});

// === Route map ===

it('keeps the route, cancel point and driver path for the compliance map', function () {
    $ride = Ride::factory()->cancelled()->create([
        'driver_id' => $this->driverUser->id,
        'pickup_lat' => 6.5000, 'pickup_lng' => 3.4000,
        'destination_lat' => 6.5500, 'destination_lng' => 3.4500,
    ]);
    $cancelledAt = now()->subMinutes(40)->startOfSecond();

    $trajectory = [];
    for ($i = 0; $i <= 5; $i++) {
        $trajectory[] = [
            'lat' => 6.5 + 0.05 * $i / 5,
            'lng' => 3.4 + 0.05 * $i / 5,
            'timestamp' => $cancelledAt->copy()->addSeconds(60 + $i * 30)->timestamp,
            'accuracy' => 8.0,
        ];
    }
    $trajectory[] = ['lat' => 6.6, 'lng' => 3.3, 'timestamp' => $cancelledAt->copy()->addMinutes(5)->timestamp, 'accuracy' => 8.0];

    Cache::put("offline_monitoring:{$ride->id}", $trajectory, 3600);
    Cache::put(OfflineTripDetectionService::CONTEXT_CACHE_PREFIX.$ride->id, [
        'cancelled_at' => $cancelledAt->toISOString(),
        'cancel_distance_from_pickup_meters' => 80.0,
        'cancel_gps_accuracy_meters' => 6.0,
        'cancel_lat' => 6.5005,
        'cancel_lng' => 3.4003,
    ], 3600);

    (new AnalyzeOfflineTripJob($ride->id, $this->driverUser->id))
        ->handle(app(OfflineTripDetectionService::class), app(SanctionService::class), app(RideRouteService::class));

    $flag = OfflineTripFlag::where('ride_id', $ride->id)->firstOrFail();

    $map = $this->withToken($this->token)
        ->getJson("/api/v1/admin/offline-flags/{$flag->id}")
        ->assertOk()
        ->json('route_map');

    expect($map['pickup'])->toMatchArray(['lat' => 6.5, 'lng' => 3.4])
        ->and($map['route']['source'])->toBe('straight_line')
        ->and(Polyline::decode($map['route']['encoded_polyline']))->toBe([['lat' => 6.5, 'lng' => 3.4], ['lat' => 6.55, 'lng' => 3.45]])
        ->and($map['corridor_meters'])->toBe(200)
        ->and($map['cancelled_at'])->toBe($cancelledAt->toISOString())
        ->and($map['cancel_point'])->toEqual(['lat' => 6.5005, 'lng' => 3.4003, 'accuracy_meters' => 6.0])
        ->and($map['driver_path'])->toHaveCount(7)
        ->and($map['driver_path'][0])->toMatchArray(['seconds_after_cancellation' => 60, 'on_route' => true, 'accuracy_meters' => 8.0])
        ->and($map['driver_path'][6]['on_route'])->toBeFalse()
        ->and($map['driver_path'][6]['seconds_after_cancellation'])->toBe(300);
});

it('returns an empty map for a flag raised before the path was kept', function () {
    $flag = complianceFlag(['detection_data' => ['route_match_percentage' => 80]]);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/offline-flags/{$flag->id}")
        ->assertOk()
        ->assertJsonPath('route_map.route', null)
        ->assertJsonPath('route_map.cancel_point', null)
        ->assertJsonPath('route_map.driver_path', []);
});

it('measures the driver against the planned road route, not a straight line', function () {
    // An L-shaped road: east, then north. The driver follows the road.
    $road = Polyline::encode([[6.50, 3.40], [6.50, 3.45], [6.55, 3.45]]);
    $trajectory = [];
    foreach ([[6.50, 3.41], [6.50, 3.43], [6.50, 3.45], [6.52, 3.45], [6.54, 3.45]] as $i => [$lat, $lng]) {
        $trajectory[] = ['lat' => $lat, 'lng' => $lng, 'timestamp' => now()->addSeconds($i * 30)->timestamp];
    }
    $route = ['pickup_lat' => 6.50, 'pickup_lng' => 3.40, 'destination_lat' => 6.55, 'destination_lng' => 3.45];
    $detection = app(OfflineTripDetectionService::class);

    $onRoad = $detection->analyzeCollocation($trajectory, $route, $road);
    $straight = $detection->analyzeCollocation($trajectory, $route);

    expect($onRoad['flagged'])->toBeTrue()
        ->and($onRoad['route_source'])->toBe('planned_route')
        ->and($onRoad['route_polyline'])->toBe($road)
        ->and($onRoad['route_match_percentage'])->toEqual(100)
        ->and($straight['flagged'])->toBeFalse()
        ->and($straight['route_source'])->toBe('straight_line');
});

it('says when an automatic suspension ends, and nothing for one pending review', function () {
    $suspendedAt = now()->subHours(10)->startOfSecond();
    suspendForCompliance(SanctionService::AUTOMATIC_SUSPENSION_REASON, $suspendedAt);
    $flag = complianceFlag(['sanction_tier' => SanctionTier::Suspension]);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/offline-flags/{$flag->id}")
        ->assertJsonPath('flag.driver_status', 'suspended')
        ->assertJsonPath('flag.driver_suspension_ends_at', $suspendedAt->copy()->addHours(48)->toISOString());

    suspendForCompliance(SanctionService::DEACTIVATION_REVIEW_REASON, $suspendedAt);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/offline-flags/{$flag->id}")
        ->assertJsonPath('flag.driver_suspension_ends_at', null);
});

// === The driver's time to respond before a Level 3 decision ===

function levelThreeFlagFromDetection(): OfflineTripFlag
{
    complianceFlag(['flagged_at' => now()->subDays(10)]);
    complianceFlag(['sanction_tier' => SanctionTier::Suspension, 'flagged_at' => now()->subDays(5)]);

    $ride = Ride::factory()->cancelled()->create(['driver_id' => test()->driverUser->id]);

    return app(SanctionService::class)->applyForRide($ride, ['route_match_percentage' => 90]);
}

it('gives the driver 48 hours to respond before a level 3 flag can be upheld or confirmed', function () {
    $flag = levelThreeFlagFromDetection();

    expect($flag->sanction_tier)->toBe(SanctionTier::Deactivation)
        ->and($flag->deactivation_response_due_at->timestamp)->toBe(now()->addHours(48)->timestamp);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/offline-flags/{$flag->id}")
        ->assertJsonPath('flag.deactivation_response_due_at', $flag->deactivation_response_due_at->toISOString())
        ->assertJsonPath('flag.allowed_actions.uphold', false)
        ->assertJsonPath('flag.allowed_actions.confirm_deactivation', false)
        ->assertJsonPath('flag.allowed_actions.overturn', true)
        ->assertJsonPath('flag.allowed_actions.de_escalate', true);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation", ['notes' => 'Third off-app trip this month.'])
        ->assertUnprocessable()
        ->assertJson(fn ($json) => $json->where('message', fn ($message) => str_starts_with($message, 'The driver has until '))->etc());

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/review", ['outcome' => 'upheld', 'notes' => 'Evidence is conclusive.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('flag');

    expect($this->driver->fresh()->status)->toBe(DriverStatus::Suspended);

    app('auth')->forgetGuards();
    $this->withToken($this->driverUser->createToken('auth')->plainTextToken)
        ->getJson('/api/v1/driver/compliance')
        ->assertJsonPath('compliance.active_sanction.deactivation_response_due_at', $flag->deactivation_response_due_at->toISOString())
        ->assertJsonPath('recent_flags.0.deactivation_response_due_at', $flag->deactivation_response_due_at->toISOString());

    $this->travel(49)->hours();
    app('auth')->forgetGuards();

    $this->withToken($this->admin->createToken('auth', ['admin'])->plainTextToken)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation", ['notes' => 'No response from the driver in 48 hours.'])
        ->assertOk()
        ->assertJsonPath('flag.driver_status', 'deactivated');
});

it('ends the wait once the driver disputes', function () {
    $flag = levelThreeFlagFromDetection();
    $flag->update(['is_disputed' => true, 'dispute_outcome' => DisputeOutcome::Pending, 'dispute_notes' => 'I drove home.']);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/review", ['outcome' => 'upheld', 'notes' => 'The driver lives in the other direction.'])
        ->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation", ['notes' => 'Dispute reviewed and upheld.'])
        ->assertOk();
});

it('lets a super admin confirm before the deadline and says so in the history', function () {
    $flag = levelThreeFlagFromDetection();
    $superAdmin = User::factory()->admin(AdminRole::SuperAdmin)->create();

    $this->withToken($superAdmin->createToken('auth', ['admin'])->plainTextToken)
        ->postJson("/api/v1/admin/offline-flags/{$flag->id}/confirm-deactivation", ['notes' => 'Passenger reported threats; deactivating now.'])
        ->assertOk()
        ->assertJsonPath('flag.status.value', 'upheld');

    $last = collect($this->withToken($superAdmin->createToken('auth', ['admin'])->plainTextToken)->getJson("/api/v1/admin/offline-flags/{$flag->id}")->json('history'))->last();
    expect($last['description'])->toBe("Upheld and deactivation confirmed before the driver's response deadline");
});

it('starts the wait when a flag is escalated or verified to level 3, and clears it on de-escalation', function () {
    $escalated = complianceFlag(['sanction_tier' => SanctionTier::Suspension]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$escalated->id}/escalate", ['notes' => 'Third trip found on review.'])
        ->assertOk()
        ->assertJsonPath('flag.allowed_actions.confirm_deactivation', false);
    expect($escalated->fresh()->deactivation_response_due_at->timestamp)->toBe(now()->addHours(48)->timestamp);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$escalated->id}/de-escalate", ['notes' => 'Back to a suspension.'])
        ->assertOk()
        ->assertJsonMissingPath('flag.deactivation_response_due_at');
    expect($escalated->fresh()->deactivation_response_due_at)->toBeNull();

    $verified = complianceFlag();
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/offline-flags/{$verified->id}/verify-cash-taking", ['notes' => 'Passenger confirmed paying cash.'])
        ->assertOk()
        ->assertJsonPath('flag.allowed_actions.confirm_deactivation', false);
    expect($verified->fresh()->deactivation_response_due_at)->not->toBeNull();
});
