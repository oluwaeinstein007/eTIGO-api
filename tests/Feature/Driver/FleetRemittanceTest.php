<?php

use App\Enums\FleetAgreementStatus;
use App\Models\DailyRemittance;
use App\Models\Driver;
use App\Models\FleetAgreement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetRemittanceService;

function driverWithFleetAgreement(): array
{
    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->create(['user_id' => $user->id]);
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);

    $admin = User::factory()->admin()->create();
    $agreement = FleetAgreement::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'daily_remittance_target' => 40000,
        'total_vehicle_cost' => 5000000,
        'created_by_admin_id' => $admin->id,
    ]);

    $token = $user->createToken('driver-auth', ['driver'])->plainTextToken;

    return [$user, $driver, $agreement, $token];
}

it('shows today remittance for driver with active agreement', function () {
    [$user, $driver, $agreement, $token] = driverWithFleetAgreement();

    $service = app(FleetRemittanceService::class);
    $service->getOrCreateTodayRemittance($agreement);

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/remittance/today');

    $response->assertOk()
        ->assertJsonPath('remittance.target_amount', '40000.00')
        ->assertJsonPath('remittance.remitted_amount', '0.00');
});

it('returns null remittance when no active agreement', function () {
    $user = User::factory()->driver()->create();
    Driver::factory()->create(['user_id' => $user->id]);
    $token = $user->createToken('driver-auth', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/remittance/today');

    $response->assertOk()
        ->assertJsonPath('remittance', null);
});

it('shows remittance history for driver', function () {
    [$user, $driver, $agreement, $token] = driverWithFleetAgreement();

    foreach (range(1, 5) as $i) {
        DailyRemittance::factory()->create([
            'agreement_id' => $agreement->id,
            'driver_id' => $driver->id,
            'date' => now()->subDays($i)->toDateString(),
        ]);
    }

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/remittance/history');

    $response->assertOk()
        ->assertJsonCount(5, 'remittances')
        ->assertJsonStructure(['remittances', 'meta']);
});

it('shows driver fleet agreement with full financial terms', function () {
    [$user, $driver, $agreement, $token] = driverWithFleetAgreement();

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/fleet-agreement');

    $response->assertOk()
        ->assertJsonPath('agreement.status', 'active')
        ->assertJsonPath('agreement.daily_remittance_target', '40000.00')
        ->assertJsonPath('agreement.total_vehicle_cost', '5000000.00')
        ->assertJsonPath('agreement.progress_percentage', fn ($v) => $v >= 0);
});

it('records ride remittance toward daily target', function () {
    [$user, $driver, $agreement, $token] = driverWithFleetAgreement();

    $service = app(FleetRemittanceService::class);
    $remittance = $service->recordRideRemittance($agreement, 5000);

    expect($remittance->remitted_amount)->toBe('5000.00')
        ->and($remittance->driver_earnings)->toBe('0.00')
        ->and($remittance->ride_count)->toBe(1)
        ->and($remittance->total_fares)->toBe('5000.00');
});

it('gives driver earnings after daily target is met', function () {
    [$user, $driver, $agreement, $token] = driverWithFleetAgreement();

    $service = app(FleetRemittanceService::class);

    $service->recordRideRemittance($agreement, 38000);
    $remittance = $service->recordRideRemittance($agreement, 5000);

    expect($remittance->remitted_amount)->toBe('40000.00')
        ->and($remittance->driver_earnings)->toBe('3000.00')
        ->and($remittance->shortfall_amount)->toBe('0.00')
        ->and($remittance->hasMetTarget())->toBeTrue();
});

it('auto-completes agreement when total vehicle cost is fully remitted', function () {
    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->create(['user_id' => $user->id]);
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);
    $admin = User::factory()->admin()->create();

    $agreement = FleetAgreement::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'daily_remittance_target' => 40000,
        'total_vehicle_cost' => 50000,
        'total_remitted' => 45000,
        'created_by_admin_id' => $admin->id,
    ]);

    $service = app(FleetRemittanceService::class);
    $service->recordRideRemittance($agreement, 6000);

    $agreement->refresh();
    expect($agreement->status)->toBe(FleetAgreementStatus::Completed)
        ->and($agreement->completed_at)->not->toBeNull();
});

it('settles daily remittance and tracks shortfall streak', function () {
    [$user, $driver, $agreement, $token] = driverWithFleetAgreement();

    $service = app(FleetRemittanceService::class);
    $remittance = $service->getOrCreateTodayRemittance($agreement);

    $service->recordRideRemittance($agreement, 30000);
    $remittance->refresh();
    $service->settleDay($remittance);

    $agreement->refresh();
    expect($agreement->shortfall_streak_days)->toBe(1);

    $remittance->refresh();
    expect($remittance->settled)->toBeTrue();
});

it('resets shortfall streak when daily target is met', function () {
    [$user, $driver, $agreement, $token] = driverWithFleetAgreement();
    $agreement->update(['shortfall_streak_days' => 3]);

    $service = app(FleetRemittanceService::class);
    $service->recordRideRemittance($agreement, 42000);

    $remittance = DailyRemittance::where('agreement_id', $agreement->id)->first();
    $service->settleDay($remittance);

    $agreement->refresh();
    expect($agreement->shortfall_streak_days)->toBe(0);
});

it('rejects ride remittance on non-active agreement', function () {
    $agreement = FleetAgreement::factory()->terminated()->create();
    $service = app(FleetRemittanceService::class);

    expect(fn () => $service->recordRideRemittance($agreement, 5000))
        ->toThrow(DomainException::class, 'Cannot record remittance on a non-active agreement.');
});

it('computes progress percentage correctly', function () {
    $agreement = FleetAgreement::factory()->create([
        'total_vehicle_cost' => 5000000,
        'total_remitted' => 1250000,
    ]);

    expect($agreement->progressPercentage())->toBe(25.0);
});

it('does not increment shortfall streak for excused days', function () {
    [$user, $driver, $agreement, $token] = driverWithFleetAgreement();
    $agreement->update(['shortfall_streak_days' => 2]);

    $service = app(FleetRemittanceService::class);
    $remittance = $service->getOrCreateTodayRemittance($agreement);
    $remittance->update(['excused_reason' => 'vehicle_downtime']);

    $service->settleDay($remittance);

    $agreement->refresh();
    expect($agreement->shortfall_streak_days)->toBe(2);
});

it('flags agreements at shortfall escalation thresholds', function () {
    $admin = \App\Models\User::factory()->admin()->create();

    $agreement3 = FleetAgreement::factory()->create([
        'shortfall_streak_days' => 3,
        'created_by_admin_id' => $admin->id,
    ]);

    $agreement7 = FleetAgreement::factory()->create([
        'shortfall_streak_days' => 7,
        'created_by_admin_id' => $admin->id,
    ]);

    $agreement14 = FleetAgreement::factory()->create([
        'shortfall_streak_days' => 14,
        'created_by_admin_id' => $admin->id,
    ]);

    $job = new \App\Jobs\RemittanceShortfallAlertJob;
    $job->handle();

    $agreement3->refresh();
    $agreement7->refresh();
    $agreement14->refresh();

    expect($agreement3->shortfall_flag)->toBe('warning')
        ->and($agreement7->shortfall_flag)->toBe('review')
        ->and($agreement14->shortfall_flag)->toBe('escalated');
});

it('does not re-flag agreements already at the correct escalation level', function () {
    $admin = \App\Models\User::factory()->admin()->create();

    $agreement = FleetAgreement::factory()->create([
        'shortfall_streak_days' => 3,
        'shortfall_flag' => 'warning',
        'shortfall_flagged_at' => now()->subDay(),
        'created_by_admin_id' => $admin->id,
    ]);

    $originalFlaggedAt = $agreement->shortfall_flagged_at;

    $job = new \App\Jobs\RemittanceShortfallAlertJob;
    $job->handle();

    $agreement->refresh();
    expect($agreement->shortfall_flagged_at->toDateTimeString())->toBe($originalFlaggedAt->toDateTimeString());
});
