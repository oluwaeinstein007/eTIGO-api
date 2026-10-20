<?php

use App\Enums\AdminRole;
use App\Enums\DriverStatus;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\SanctionService;
use Database\Seeders\OfflineFlagsDemoSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    City::factory()->create(['slug' => 'lagos', 'name' => 'Lagos']);
    VehicleClass::factory()->create(['name' => 'economy', 'display_name' => 'Economy']);
    VehicleClass::factory()->create(['name' => 'comfort', 'display_name' => 'Comfort']);
    User::factory()->admin(AdminRole::Operations)->create(['email' => 'ops@etigo.com']);
});

it('seeds flags at every tier and status and replaces them on a re-run', function () {
    app(OfflineFlagsDemoSeeder::class)->run();
    app(OfflineFlagsDemoSeeder::class)->run();

    $flags = OfflineTripFlag::all();

    expect($flags)->toHaveCount(22)
        ->and(Ride::count())->toBe(22)
        ->and($flags->map(fn (OfflineTripFlag $flag) => $flag->status()->value)->countBy()->sortKeys()->all())
        ->toBe(['disputed' => 3, 'flagged' => 12, 'overturned' => 3, 'upheld' => 4])
        ->and($flags->pluck('sanction_tier')->map->value->countBy()->sortKeys()->all())
        ->toBe([1 => 10, 2 => 7, 3 => 5])
        ->and(AuditLog::where('event', 'offline_trip_flagged')->count())->toBe(22)
        ->and(Driver::where('status', DriverStatus::Suspended)->pluck('suspension_reason')->sort()->values()->all())
        ->toBe([
            SanctionService::AUTOMATIC_SUSPENSION_REASON,
            SanctionService::DEACTIVATION_REVIEW_REASON,
            SanctionService::DEACTIVATION_REVIEW_REASON,
            SanctionService::DEACTIVATION_REVIEW_REASON,
        ])
        ->and(Driver::where('status', DriverStatus::Deactivated)->count())->toBe(1)
        ->and(Driver::where('is_online', true)->count())->toBe(0);

    // Every status at every level.
    $matrix = $flags->map(fn (OfflineTripFlag $flag) => $flag->status()->value.'-L'.$flag->sanction_tier->value)->unique()->sort()->values()->all();
    expect($matrix)->toBe([
        'disputed-L1', 'disputed-L2', 'disputed-L3',
        'flagged-L1', 'flagged-L2', 'flagged-L3',
        'overturned-L1', 'overturned-L2', 'overturned-L3',
        'upheld-L1', 'upheld-L2', 'upheld-L3',
    ]);

    // Each flag's evidence comes from running the detection on its path.
    foreach ($flags as $flag) {
        $map = $flag->routeMap();
        expect($map['driver_path'])->toHaveCount($flag->evidence()['location_samples'])
            ->and(collect($map['driver_path'])->where('on_route', true)->count())->toBe($flag->evidence()['on_route_samples'])
            ->and($map['route'])->not->toBeNull()
            ->and($map['cancel_point'])->not->toBeNull()
            ->and($flag->detection_data['flagged'])->toBeTrue();
    }

    // Reference numbers follow the flag dates.
    $byDate = $flags->sortBy('flagged_at')->pluck('reference_number')->values()->all();
    expect($byDate)->toBe($flags->sortBy('reference_number')->pluck('reference_number')->values()->all());
});

it('leaves rides it did not create alone when it re-runs', function () {
    app(OfflineFlagsDemoSeeder::class)->run();

    $demoDriver = User::where('email', 'like', 'driver.compliance.kola@%')->first();
    $otherRide = Ride::factory()->create([
        'driver_id' => $demoDriver->id,
        'city_id' => City::first()->id,
        'vehicle_class_id' => VehicleClass::where('name', 'economy')->first()->id,
    ]);
    $otherFlag = OfflineTripFlag::factory()->create(['ride_id' => $otherRide->id, 'driver_id' => $demoDriver->id]);

    app(OfflineFlagsDemoSeeder::class)->run();

    expect(Ride::whereKey($otherRide->id)->exists())->toBeTrue()
        ->and(OfflineTripFlag::whereKey($otherFlag->id)->exists())->toBeTrue()
        ->and(OfflineTripFlag::count())->toBe(23);
});

it('shows the seeded flags on the admin compliance screens', function () {
    app(OfflineFlagsDemoSeeder::class)->run();

    Sanctum::actingAs(User::where('email', 'ops@etigo.com')->first());

    $this->getJson('/api/v1/admin/offline-flags')
        ->assertOk()
        ->assertJsonPath('meta.total', 22)
        ->assertJsonPath('flags.0.sanction_tier.value', 3);

    $this->getJson('/api/v1/admin/offline-flags?status=disputed')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);

    $overturned = OfflineTripFlag::where('dispute_outcome', 'overturned')->where('sanction_tier', 1)->first();

    $this->getJson("/api/v1/admin/offline-flags/{$overturned->id}")
        ->assertOk()
        ->assertJsonPath('flag.status.value', 'overturned')
        ->assertJsonPath('flag.resolved_by.email', 'ops@etigo.com')
        ->assertJsonPath('history.0.event', 'offline_trip_flagged')
        ->assertJsonPath('history.1.event', 'offline_flag_disputed')
        ->assertJsonPath('history.2.description', 'Overturned')
        ->assertJson(fn ($json) => $json->where('flag.detection_summary', fn ($summary) => str_contains($summary, '62.5%'))->etc());

    $musaL3 = OfflineTripFlag::where('sanction_tier', 3)->whereHas('driver', fn ($query) => $query->where('first_name', 'Musa'))->first();

    $this->getJson("/api/v1/admin/offline-flags/{$musaL3->id}")
        ->assertOk()
        ->assertJsonCount(2, 'previous_flags')
        ->assertJsonPath('flag.allowed_actions.confirm_deactivation', true)
        ->assertJsonCount(27, 'route_map.driver_path');

    $segunL3 = OfflineTripFlag::whereNotNull('deactivation_confirmed_at')->first();

    $this->getJson("/api/v1/admin/offline-flags/{$segunL3->id}")
        ->assertOk()
        ->assertJsonPath('flag.status.value', 'upheld')
        ->assertJsonPath('flag.sanction_action', 'deactivated')
        ->assertJsonPath('flag.driver_status', 'deactivated')
        ->assertJsonPath('flag.allowed_actions.confirm_deactivation', false)
        ->assertJsonPath('history.1.description', 'Upheld and deactivation confirmed');

    $appealed = OfflineTripFlag::where('dispute_outcome', 'overturned')->where('sanction_tier', 2)->first();

    $this->getJson("/api/v1/admin/offline-flags/{$appealed->id}")
        ->assertOk()
        ->assertJsonPath('history.3.description', 'Overturned on appeal');

    $cashTaking = OfflineTripFlag::whereNotNull('cash_taking_verified_at')->first();

    $this->getJson("/api/v1/admin/offline-flags/{$cashTaking->id}")
        ->assertOk()
        ->assertJsonPath('flag.sanction_tier.value', 3)
        ->assertJsonPath('flag.allowed_actions.confirm_deactivation', false)
        ->assertJson(fn ($json) => $json->where('flag.deactivation_response_due_at', fn ($due) => now()->lt($due))->etc())
        ->assertJsonPath('history.1.description', 'Cash-taking verified from Level 1 to Level 3');
});

it('does not run in production', function () {
    $this->app['env'] = 'production';

    app(OfflineFlagsDemoSeeder::class)->run();

    expect(OfflineTripFlag::count())->toBe(0);
});
