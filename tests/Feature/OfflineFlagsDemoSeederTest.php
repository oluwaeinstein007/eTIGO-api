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

    expect($flags)->toHaveCount(12)
        ->and(Ride::count())->toBe(12)
        ->and($flags->map(fn (OfflineTripFlag $flag) => $flag->status()->value)->countBy()->sortKeys()->all())
        ->toBe(['disputed' => 2, 'flagged' => 7, 'overturned' => 1, 'upheld' => 2])
        ->and($flags->pluck('sanction_tier')->map->value->countBy()->sortKeys()->all())
        ->toBe([1 => 6, 2 => 4, 3 => 2])
        ->and(AuditLog::where('event', 'offline_trip_flagged')->count())->toBe(12)
        ->and(Driver::where('status', DriverStatus::Suspended)->pluck('suspension_reason')->sort()->values()->all())
        ->toBe([
            SanctionService::AUTOMATIC_SUSPENSION_REASON,
            SanctionService::DEACTIVATION_REVIEW_REASON,
            SanctionService::DEACTIVATION_REVIEW_REASON,
        ])
        ->and(Driver::where('is_online', true)->count())->toBe(0);

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
        ->and(OfflineTripFlag::count())->toBe(13);
});

it('shows the seeded flags on the admin compliance screens', function () {
    app(OfflineFlagsDemoSeeder::class)->run();

    Sanctum::actingAs(User::where('email', 'ops@etigo.com')->first());

    $this->getJson('/api/v1/admin/offline-flags')
        ->assertOk()
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('flags.0.sanction_tier.value', 3);

    $this->getJson('/api/v1/admin/offline-flags?status=disputed')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $overturned = OfflineTripFlag::where('dispute_outcome', 'overturned')->first();

    $this->getJson("/api/v1/admin/offline-flags/{$overturned->id}")
        ->assertOk()
        ->assertJsonPath('flag.status.value', 'overturned')
        ->assertJsonPath('flag.resolved_by.email', 'ops@etigo.com')
        ->assertJsonPath('history.0.event', 'offline_trip_flagged')
        ->assertJsonPath('history.1.event', 'offline_flag_disputed')
        ->assertJsonPath('history.2.description', 'Overturned')
        ->assertJson(fn ($json) => $json->where('flag.detection_summary', fn ($summary) => str_contains($summary, '62.5%'))->etc());

    $musaL3 = OfflineTripFlag::where('sanction_tier', 3)->where('is_disputed', false)->first();

    $this->getJson("/api/v1/admin/offline-flags/{$musaL3->id}")
        ->assertOk()
        ->assertJsonCount(2, 'previous_flags');
});

it('does not run in production', function () {
    $this->app['env'] = 'production';

    app(OfflineFlagsDemoSeeder::class)->run();

    expect(OfflineTripFlag::count())->toBe(0);
});
