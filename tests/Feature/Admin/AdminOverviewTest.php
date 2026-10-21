<?php

use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Jobs\CaptureOperationsSnapshotJob;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Dispute;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\OperationsSnapshot;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use App\Services\OperationsMetricsService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->lagos = City::factory()->create(['name' => 'Lagos']);
    $this->vehicleClass = VehicleClass::factory()->create();

    $this->locations = Mockery::mock(DriverLocationService::class);
    $this->locations->shouldReceive('getDriverLocations')->andReturn([])->byDefault();
    $this->app->instance(DriverLocationService::class, $this->locations);
});

function overviewAdmin(AdminRole $role = AdminRole::SuperAdmin): User
{
    return User::factory()->admin($role)->create(['first_name' => 'Tessy', 'last_name' => 'Okoro']);
}

function overviewRide(City $city, array $attributes = [], ?string $state = null): Ride
{
    $factory = Ride::factory();

    if ($state) {
        $factory = $factory->{$state}();
    }

    return $factory->create(['city_id' => $city->id, 'vehicle_class_id' => test()->vehicleClass->id, ...$attributes]);
}

function overviewOnlineDriver(City $city): Driver
{
    return Driver::factory()->online()->create(['city_id' => $city->id]);
}

/*
|--------------------------------------------------------------------------
| GET /admin/overview
|--------------------------------------------------------------------------
*/

it('gives a super admin every card with live counts', function () {
    overviewRide($this->lagos, state: 'searching');
    overviewRide($this->lagos, state: 'inProgress');
    overviewRide($this->lagos, state: 'cancelled');

    // Two online drivers, one of them on a trip.
    $busy = overviewOnlineDriver($this->lagos);
    overviewOnlineDriver($this->lagos);
    overviewRide($this->lagos, ['driver_id' => $busy->user_id], 'driverEnRoute');
    Driver::factory()->create(['city_id' => $this->lagos->id, 'status' => DriverStatus::PendingReview]);

    // Cases hang off finished trips, so they do not add to the live counts.
    $done = fn () => ['ride_id' => overviewRide($this->lagos, state: 'completed')->id];

    // Open disputes: one on track, one past its first-response target.
    Dispute::factory()->create($done());
    Dispute::factory()->create([...$done(), 'first_response_due_at' => now()->subMinute()]);
    Dispute::factory()->resolved()->create($done());

    SosIncident::factory()->create($done());                     // triggered: critical
    SosIncident::factory()->operatorAssigned()->create($done()); // active, being handled
    SosIncident::factory()->resolved()->create($done());

    OfflineTripFlag::factory()->create($done());
    OfflineTripFlag::factory()->tier3()->create($done());
    OfflineTripFlag::factory()->create([...$done(), 'resolved_at' => now()]);

    overviewRide($this->lagos, state: 'noDriverFound');

    $response = $this->actingAs(overviewAdmin())->getJson('/api/v1/admin/overview');

    $response->assertOk()
        ->assertJsonPath('stats.active_rides.count', 3)
        ->assertJsonPath('stats.active_rides.change_pct', null)
        ->assertJsonPath('stats.drivers_online.count', 2)
        ->assertJsonPath('stats.drivers_online.on_trip', 1)
        ->assertJsonPath('stats.drivers_online.utilisation_pct', 50)
        ->assertJsonPath('stats.open_disputes.count', 2)
        ->assertJsonPath('stats.open_disputes.overdue', 1)
        ->assertJsonPath('stats.revenue.currency', 'NGN')
        ->assertJsonPath('attention.safety_alerts.count', 2)
        ->assertJsonPath('attention.safety_alerts.critical', 1)
        ->assertJsonPath('attention.compliance_reviews.count', 2)
        ->assertJsonPath('attention.compliance_reviews.high_severity', 1)
        ->assertJsonPath('attention.pending_kyc.count', 1)
        ->assertJsonPath('attention.unmatched_requests.count', 2)
        ->assertJsonPath('can_view_live_operations', true);
});

it('compares live counts with the snapshot from this time yesterday', function () {
    $this->freezeTime();

    OperationsSnapshot::create([
        'captured_at' => now()->subDay()->addMinutes(3),
        'city_id' => $this->lagos->id,
        'active_rides' => 2,
        'drivers_online' => 4,
        'drivers_on_trip' => 1,
    ]);
    // Too far from this time yesterday to count.
    OperationsSnapshot::create([
        'captured_at' => now()->subDay()->subHour(),
        'city_id' => $this->lagos->id,
        'active_rides' => 50,
        'drivers_online' => 50,
        'drivers_on_trip' => 0,
    ]);

    overviewRide($this->lagos, state: 'searching');
    overviewRide($this->lagos, state: 'searching');
    overviewRide($this->lagos, state: 'searching');
    overviewOnlineDriver($this->lagos);

    $this->actingAs(overviewAdmin())->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('stats.active_rides.count', 3)
        ->assertJsonPath('stats.active_rides.change_pct', 50)
        ->assertJsonPath('stats.drivers_online.change_pct', -75)
        ->assertJsonPath('stats.drivers_online.compared_at', now()->subDay()->addMinutes(3)->startOfSecond()->toIso8601String());
});

it('totals revenue from midnight in Lagos against the same stretch of yesterday', function () {
    // 10:00 Lagos time.
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00', 'UTC'));

    overviewRide($this->lagos, ['completed_at' => Carbon::parse('2026-10-21 08:00', 'UTC'), 'final_fare_amount' => 3000], 'completed');
    overviewRide($this->lagos, ['completed_at' => Carbon::parse('2026-10-20 23:30', 'UTC'), 'final_fare_amount' => 1000], 'completed'); // 00:30 Lagos today
    overviewRide($this->lagos, ['completed_at' => Carbon::parse('2026-10-20 22:30', 'UTC'), 'final_fare_amount' => 9999], 'completed'); // 23:30 Lagos yesterday, after midnight UTC cut
    // Yesterday before 10:00 Lagos counts as the comparison; after it does not.
    overviewRide($this->lagos, ['completed_at' => Carbon::parse('2026-10-20 07:00', 'UTC'), 'final_fare_amount' => 2000], 'completed');
    overviewRide($this->lagos, ['completed_at' => Carbon::parse('2026-10-20 12:00', 'UTC'), 'final_fare_amount' => 7000], 'completed');

    $this->actingAs(overviewAdmin(AdminRole::Finance))->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('stats.revenue.amount', 4000)
        ->assertJsonPath('stats.revenue.completed_rides', 2)
        ->assertJsonPath('stats.revenue.previous_amount', 2000)
        ->assertJsonPath('stats.revenue.change_pct', 100)
        ->assertJsonPath('stats.revenue.timezone', 'Africa/Lagos');
});

it('only shows each role the cards it can follow up on', function (AdminRole $role, array $visible) {
    $response = $this->actingAs(overviewAdmin($role))->getJson('/api/v1/admin/overview')->assertOk();

    $cards = [
        'stats.active_rides', 'stats.drivers_online', 'stats.open_disputes', 'stats.revenue',
        'attention.safety_alerts', 'attention.compliance_reviews', 'attention.pending_kyc', 'attention.unmatched_requests',
    ];

    foreach ($cards as $card) {
        in_array($card, $visible, true)
            ? expect($response->json($card))->not->toBeNull("{$role->value} should see {$card}")
            : expect($response->json($card))->toBeNull("{$role->value} should not see {$card}");
    }

    expect($response->json('can_view_live_operations'))->toBe(in_array('stats.active_rides', $visible, true));
})->with([
    'operations' => [AdminRole::Operations, ['stats.active_rides', 'stats.drivers_online', 'stats.revenue', 'attention.compliance_reviews', 'attention.pending_kyc', 'attention.unmatched_requests']],
    'finance' => [AdminRole::Finance, ['stats.revenue']],
    'support' => [AdminRole::Support, ['stats.open_disputes']],
    'support lead' => [AdminRole::SupportLead, ['stats.open_disputes']],
    'safety operator' => [AdminRole::SafetyOperator, ['stats.open_disputes', 'attention.safety_alerts', 'attention.compliance_reviews', 'attention.pending_kyc']],
]);

it('counts only the disputes in the support queues for support staff', function () {
    Dispute::factory()->create();
    Dispute::factory()->safety()->create();

    $this->actingAs(overviewAdmin(AdminRole::Support))->getJson('/api/v1/admin/overview')
        ->assertJsonPath('stats.open_disputes.count', 1);

    $this->actingAs(overviewAdmin(AdminRole::SafetyOperator))->getJson('/api/v1/admin/overview')
        ->assertJsonPath('stats.open_disputes.count', 2);
});

it('is closed to non-admins', function () {
    $this->actingAs(User::factory()->passenger()->create())
        ->getJson('/api/v1/admin/overview')
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Recent activity
|--------------------------------------------------------------------------
*/

it('lists recent activity newest first with readable subjects', function () {
    $admin = overviewAdmin();
    $approver = overviewAdmin();

    $driver = Driver::factory()->approved()->create(['city_id' => $this->lagos->id]);
    $driver->user->update(['first_name' => 'Amina', 'last_name' => 'Yusuf']);
    Vehicle::factory()->for($driver)->create(['make' => 'Toyota', 'model' => 'Corolla', 'vehicle_class_id' => $this->vehicleClass->id]);

    $config = PricingConfig::factory()->forCityAndClass($this->lagos, $this->vehicleClass)->create();
    $dispute = Dispute::factory()->create();
    $ride = overviewRide($this->lagos, state: 'completed');

    $this->travelTo(now()->subMinutes(10));
    AuditLog::record($config, 'pricing_config_created', $approver);
    $this->travelBack();
    $this->travelTo(now()->subMinutes(5));
    AuditLog::record($dispute, 'dispute_assigned', $approver, ['assigned_admin_id' => null], ['assigned_admin_id' => $admin->id]);
    $this->travelBack();
    AuditLog::record($driver, 'driver_approved', $approver);
    AuditLog::record($ride, 'ride_completed', null); // per-trip noise: left out

    $activity = $this->actingAs($admin)->getJson('/api/v1/admin/overview')->assertOk()->json('recent_activity');

    expect($activity)->toHaveCount(3)
        ->and($activity[0])->toMatchArray([
            'event' => 'driver_approved',
            'category' => 'driver',
            'title' => 'Driver verification approved',
            'subtitle' => 'Amina Yusuf · Toyota Corolla · Lagos',
        ])
        ->and($activity[0]['subject'])->toMatchArray(['type' => 'driver', 'id' => $driver->id])
        ->and($activity[0]['actor']['name'])->toBe('Tessy Okoro')
        ->and($activity[1]['title'])->toBe('Dispute assigned to you')
        ->and($activity[1]['subtitle'])->toBe($dispute->reference_number.' · '.$dispute->category->label())
        ->and($activity[2]['title'])->toBe('Pricing version published')
        ->and($activity[2]['subtitle'])->toBe("Lagos · {$this->vehicleClass->display_name} · v{$config->version}");
});

it('keeps activity to the areas the admin can open', function () {
    $driver = Driver::factory()->approved()->create(['city_id' => $this->lagos->id]);
    $supportCase = Dispute::factory()->create();
    $safetyCase = Dispute::factory()->safety()->create();

    AuditLog::record($driver, 'driver_approved', null);
    AuditLog::record($supportCase->ride, 'dispute_filed', null, null, ['dispute_id' => $supportCase->id]);
    AuditLog::record($safetyCase->ride, 'dispute_filed', null, null, ['dispute_id' => $safetyCase->id]);

    $support = $this->actingAs(overviewAdmin(AdminRole::Support))->getJson('/api/v1/admin/overview')->json('recent_activity');
    expect($support)->toHaveCount(1)
        ->and($support[0]['subject']['id'])->toBe($supportCase->id);

    $finance = $this->actingAs(overviewAdmin(AdminRole::Finance))->getJson('/api/v1/admin/overview')->json('recent_activity');
    expect($finance)->toBe([]);
});

it('keeps reading past cases the admin cannot see to fill the list', function () {
    $supportCase = Dispute::factory()->create();
    $this->travelTo(now()->subHour());
    AuditLog::record($supportCase->ride, 'dispute_filed', null, null, ['dispute_id' => $supportCase->id]);
    $this->travelBack();

    // More newer Safety-queue events than one page holds.
    $safetyCase = Dispute::factory()->safety()->create();
    foreach (range(1, 40) as $i) {
        AuditLog::record($safetyCase, 'dispute_assigned', null, null, ['assigned_admin_id' => null]);
    }

    $activity = $this->actingAs(overviewAdmin(AdminRole::Support))
        ->getJson('/api/v1/admin/overview?activity_limit=1')
        ->json('recent_activity');

    expect($activity)->toHaveCount(1)
        ->and($activity[0]['subject']['id'])->toBe($supportCase->id);
});

it('points an SOS raised on a ride at the incident', function () {
    $incident = SosIncident::factory()->create();
    AuditLog::record($incident->ride, 'sos_triggered', null, null, ['incident_id' => $incident->id]);

    $activity = $this->actingAs(overviewAdmin(AdminRole::SafetyOperator))->getJson('/api/v1/admin/overview')->json('recent_activity');

    expect($activity[0]['subject'])->toMatchArray(['type' => 'sos_incident', 'id' => $incident->id])
        ->and($activity[0]['subtitle'])->toBe($incident->reference_number.' · '.$incident->ride->reference_number);
});

/*
|--------------------------------------------------------------------------
| GET /admin/overview/live-operations
|--------------------------------------------------------------------------
*/

it('shows the busiest city by default with markers and the latest trips', function () {
    $abuja = City::factory()->create(['name' => 'Abuja']);
    overviewRide($abuja, state: 'searching');

    $driver = overviewOnlineDriver($this->lagos);
    $located = overviewRide($this->lagos, [
        'driver_id' => $driver->user_id,
        'pickup_address' => 'Bodija Market, Ibadan',
        'created_at' => now()->subMinutes(3),
    ], 'inProgress');
    $waiting = overviewRide($this->lagos, ['pickup_lat' => 6.5, 'pickup_lng' => 3.35, 'created_at' => now()->subMinute()], 'searching');

    $this->locations->shouldReceive('getDriverLocations')
        ->with([(string) $driver->id])
        ->andReturn([(string) $driver->id => ['lat' => 6.45, 'lng' => 3.4, 'heading' => 0.0, 'speed' => 0.0, 'timestamp' => now()->timestamp]]);

    $response = $this->actingAs(overviewAdmin(AdminRole::Operations))
        ->getJson('/api/v1/admin/overview/live-operations?rides_limit=1');

    $response->assertOk()
        ->assertJsonPath('city.name', 'Lagos')
        ->assertJsonPath('cities.0.name', 'Lagos')
        ->assertJsonPath('cities.0.active_rides', 2)
        ->assertJsonPath('cities.1.name', 'Abuja')
        ->assertJsonPath('summary.active_rides', 2)
        ->assertJsonPath('summary.needs_attention', 0)
        ->assertJsonCount(2, 'markers')
        ->assertJsonPath('markers.0.ride_id', $waiting->id)
        ->assertJsonPath('markers.0.position_source', 'pickup')
        ->assertJsonPath('markers.0.lat', 6.5)
        ->assertJsonPath('markers.1.ride_id', $located->id)
        ->assertJsonPath('markers.1.position_source', 'driver')
        ->assertJsonPath('markers.1.lat', 6.45)
        ->assertJsonCount(1, 'rides')
        ->assertJsonPath('rides.0.id', $waiting->id)
        ->assertJsonPath('rides.0.status_label', RideStatus::Searching->label());
});

it('flags trips with an active SOS or a driver whose app has gone quiet', function () {
    $sosRide = overviewRide($this->lagos, state: 'searching');
    SosIncident::factory()->create(['ride_id' => $sosRide->id]);

    $quiet = overviewOnlineDriver($this->lagos);
    $quietRide = overviewRide($this->lagos, ['driver_id' => $quiet->user_id], 'driverEnRoute');

    $this->locations->shouldReceive('getDriverLocations')
        ->andReturn([(string) $quiet->id => ['lat' => 6.45, 'lng' => 3.4, 'heading' => 0.0, 'speed' => 0.0, 'timestamp' => now()->subMinutes(10)->timestamp]]);

    $rides = collect($this->actingAs(overviewAdmin(AdminRole::Operations))
        ->getJson("/api/v1/admin/overview/live-operations?city_id={$this->lagos->id}&rides_limit=5")
        ->assertOk()
        ->assertJsonPath('summary.needs_attention', 2)
        ->json('rides'))->keyBy('id');

    expect($rides[$sosRide->id]['attention_reasons'])->toBe(['sos'])
        ->and($rides[$quietRide->id]['attention_reasons'])->toBe(['driver_location_missing']);
});

it('returns an empty panel when nothing is moving', function () {
    $this->actingAs(overviewAdmin(AdminRole::Operations))
        ->getJson('/api/v1/admin/overview/live-operations')
        ->assertOk()
        ->assertJsonPath('city', null)
        ->assertJsonPath('markers', [])
        ->assertJsonPath('summary.active_rides', 0);
});

it('keeps live operations to operations', function () {
    $this->actingAs(overviewAdmin(AdminRole::Finance))
        ->getJson('/api/v1/admin/overview/live-operations')
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| GET /admin/search
|--------------------------------------------------------------------------
*/

it('finds trips, people and cases in one call', function () {
    $passenger = User::factory()->passenger()->create(['first_name' => 'Kemi', 'last_name' => 'Balogun']);
    $ride = overviewRide($this->lagos, ['passenger_id' => $passenger->id], 'searching');
    $driver = Driver::factory()->approved()->create(['city_id' => $this->lagos->id]);
    $driver->user->update(['first_name' => 'Seyi', 'last_name' => 'Adeyemi']);
    $dispute = Dispute::factory()->create(['reported_by_user_id' => $passenger->id]);

    $results = $this->actingAs(overviewAdmin())
        ->getJson('/api/v1/admin/search?q=kemi bal')
        ->assertOk()
        ->json('results');

    expect(collect($results['rides'])->pluck('id'))->toContain($ride->id)
        ->and(collect($results['passengers'])->pluck('id')->all())->toBe([$passenger->id])
        ->and(collect($results['disputes'])->pluck('id')->all())->toBe([$dispute->id])
        ->and($results['drivers'])->toBe([]);

    $this->actingAs(overviewAdmin())
        ->getJson('/api/v1/admin/search?q='.$driver->reference_number)
        ->assertJsonPath('results.drivers.0.name', 'Seyi Adeyemi');
});

it('puts an exact reference first ahead of newer partial matches', function () {
    $exact = overviewRide($this->lagos, ['reference_number' => 'TRP-NG-00001', 'created_at' => now()->subDay()], 'searching');
    foreach (range(10, 15) as $n) {
        overviewRide($this->lagos, ['reference_number' => "TRP-NG-0000{$n}"], 'searching');
    }

    $this->actingAs(overviewAdmin())
        ->getJson('/api/v1/admin/search?q=trp-ng-00001')
        ->assertOk()
        ->assertJsonCount(5, 'results.rides')
        ->assertJsonPath('results.rides.0.id', $exact->id);
});

it('only searches the areas the admin can open', function () {
    $results = $this->actingAs(overviewAdmin(AdminRole::Finance))
        ->getJson('/api/v1/admin/search?q=anything')
        ->assertOk()
        ->json('results');

    expect($results)->each->toBeNull();
});

it('treats search wildcards literally and needs two characters', function () {
    User::factory()->passenger()->create(['first_name' => 'Ada']);

    $this->actingAs(overviewAdmin())->getJson('/api/v1/admin/search?q=%25%25')
        ->assertOk()
        ->assertJsonPath('results.passengers', []);

    $this->actingAs(overviewAdmin())->getJson('/api/v1/admin/search?q=a')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');
});

/*
|--------------------------------------------------------------------------
| Snapshots
|--------------------------------------------------------------------------
*/

it('records per-city counts that add up to the overview totals', function () {
    $abuja = City::factory()->create();
    overviewRide($this->lagos, state: 'searching');
    overviewRide($abuja, state: 'searching');
    $busy = overviewOnlineDriver($this->lagos);
    overviewRide($this->lagos, ['driver_id' => $busy->user_id], 'inProgress');

    (new CaptureOperationsSnapshotJob)->handle(app(OperationsMetricsService::class));
    (new CaptureOperationsSnapshotJob)->handle(app(OperationsMetricsService::class)); // same minute: replaced

    expect(OperationsSnapshot::count())->toBe(2)
        ->and(OperationsSnapshot::where('city_id', $this->lagos->id)->first())
        ->active_rides->toBe(2)
        ->drivers_online->toBe(1)
        ->drivers_on_trip->toBe(1);

    expect(OperationsSnapshot::totalsNear(now()))->toMatchArray([
        'active_rides' => 3,
        'drivers_online' => 1,
        'drivers_on_trip' => 1,
    ]);
});

it('keeps a zero row when nothing is happening so the comparison still works', function () {
    (new CaptureOperationsSnapshotJob)->handle(app(OperationsMetricsService::class));

    expect(OperationsSnapshot::totalsNear(now()))->toMatchArray(['active_rides' => 0, 'drivers_online' => 0]);
});
