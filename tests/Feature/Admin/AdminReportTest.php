<?php

use App\Enums\AdminRole;
use App\Enums\CancellationReason;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;

function reportAdminToken(AdminRole $role = AdminRole::SuperAdmin): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    return [$admin, $token];
}

// ── Ride Volume ──

it('returns ride volume report', function () {
    [, $token] = reportAdminToken(AdminRole::Operations);

    $city = City::factory()->create();
    Ride::factory()->completed()->count(3)->create(['city_id' => $city->id]);
    Ride::factory()->cancelled()->count(2)->create(['city_id' => $city->id]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/ride-volume');

    $response->assertOk()
        ->assertJsonStructure([
            'report' => [
                'type',
                'period',
                'total_rides',
                'by_status',
                'by_city',
                'by_vehicle_class',
                'generated_at',
            ],
        ])
        ->assertJsonPath('report.type', 'ride_volume')
        ->assertJsonPath('report.total_rides', 5);
});

it('filters ride volume by date range', function () {
    [, $token] = reportAdminToken();

    Ride::factory()->completed()->create(['created_at' => now()->subDays(10)]);
    Ride::factory()->completed()->create(['created_at' => now()->subDays(5)]);
    Ride::factory()->completed()->create(['created_at' => now()->subDay()]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/ride-volume?from='.now()->subDays(6)->toDateString().'&to='.now()->toDateString());

    $response->assertOk()
        ->assertJsonPath('report.total_rides', 2);
});

it('filters ride volume by city', function () {
    [, $token] = reportAdminToken();

    $city = City::factory()->create();
    Ride::factory()->completed()->count(2)->create(['city_id' => $city->id]);
    Ride::factory()->completed()->create();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/ride-volume?city_id='.$city->id);

    $response->assertOk()
        ->assertJsonPath('report.total_rides', 2);
});

it('returns ride volume trend grouped by day', function () {
    [, $token] = reportAdminToken();

    Ride::factory()->completed()->create(['created_at' => now()->subDays(2)]);
    Ride::factory()->completed()->create(['created_at' => now()->subDay()]);
    Ride::factory()->completed()->count(2)->create(['created_at' => now()]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/ride-volume?group_by=day');

    $response->assertOk()
        ->assertJsonStructure(['report' => ['trend']]);

    expect($response->json('report.trend'))->toBeArray()
        ->and(count($response->json('report.trend')))->toBeGreaterThanOrEqual(2);
});

// ── Completion Rate ──

it('returns completion rate report', function () {
    [, $token] = reportAdminToken(AdminRole::Finance);

    $city = City::factory()->create();
    $vc = VehicleClass::factory()->create();
    Ride::factory()->completed()->count(7)->create(['city_id' => $city->id, 'vehicle_class_id' => $vc->id]);
    Ride::factory()->cancelled()->count(2)->create([
        'city_id' => $city->id,
        'vehicle_class_id' => $vc->id,
        'cancellation_reason' => CancellationReason::ChangedMind,
    ]);
    Ride::factory()->noDriverFound()->create(['city_id' => $city->id, 'vehicle_class_id' => $vc->id]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/completion-rate');

    $response->assertOk()
        ->assertJsonStructure([
            'report' => [
                'type',
                'total_rides',
                'completed',
                'cancelled',
                'no_driver_found',
                'completion_rate',
                'cancellation_rate',
                'cancellation_reasons',
                'generated_at',
            ],
        ])
        ->assertJsonPath('report.type', 'completion_rate')
        ->assertJsonPath('report.total_rides', 10)
        ->assertJsonPath('report.completed', 7)
        ->assertJsonPath('report.cancelled', 2)
        ->assertJsonPath('report.no_driver_found', 1)
        ->assertJsonPath('report.completion_rate', 70);
});

it('breaks down cancellation reasons', function () {
    [, $token] = reportAdminToken();

    Ride::factory()->cancelled()->count(3)->create([
        'cancellation_reason' => CancellationReason::DriverTooFar,
    ]);
    Ride::factory()->cancelled()->create([
        'cancellation_reason' => CancellationReason::WaitTooLong,
    ]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/completion-rate');

    $response->assertOk();
    $reasons = $response->json('report.cancellation_reasons');
    expect($reasons)->toHaveKey('driver_too_far', 3)
        ->and($reasons)->toHaveKey('wait_too_long', 1);
});

// ── Revenue ──

it('returns revenue report', function () {
    [, $token] = reportAdminToken(AdminRole::Finance);

    $city = City::factory()->create();
    $rides = Ride::factory()->completed()->count(5)->create([
        'city_id' => $city->id,
        'final_fare_amount' => 5000.00,
    ]);

    foreach ($rides as $ride) {
        Payment::factory()->create([
            'ride_id' => $ride->id,
            'amount' => 5000.00,
            'status' => PaymentStatus::Captured,
            'method' => $ride->payment_method,
            'tip_amount' => 200.00,
        ]);
    }

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/revenue');

    $response->assertOk()
        ->assertJsonStructure([
            'report' => [
                'type',
                'currency',
                'total_revenue',
                'total_tips',
                'ride_count',
                'average_fare',
                'by_payment_method',
                'by_city',
                'generated_at',
            ],
        ])
        ->assertJsonPath('report.type', 'revenue')
        ->assertJsonPath('report.ride_count', 5)
        ->assertJsonPath('report.ride_count', 5);

    expect((float) $response->json('report.total_revenue'))->toBe(25000.0)
        ->and((float) $response->json('report.average_fare'))->toBe(5000.0);
});

it('breaks down revenue by payment method', function () {
    [, $token] = reportAdminToken();

    Ride::factory()->completed()->count(3)->create([
        'payment_method' => PaymentMethod::Cash,
        'final_fare_amount' => 2000.00,
    ]);
    Ride::factory()->completed()->count(2)->create([
        'payment_method' => PaymentMethod::Card,
        'final_fare_amount' => 3000.00,
    ]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/revenue');

    $response->assertOk();
    $methods = collect($response->json('report.by_payment_method'));
    expect($methods->firstWhere('method', 'cash')['count'])->toBe(3)
        ->and($methods->firstWhere('method', 'card')['count'])->toBe(2);
});

it('returns revenue trend grouped by month', function () {
    [, $token] = reportAdminToken();

    Ride::factory()->completed()->create([
        'created_at' => now()->subMonth(),
        'completed_at' => now()->subMonth(),
        'final_fare_amount' => 3000.00,
    ]);
    Ride::factory()->completed()->create([
        'created_at' => now(),
        'completed_at' => now(),
        'final_fare_amount' => 5000.00,
    ]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/revenue?group_by=month');

    $response->assertOk()
        ->assertJsonStructure(['report' => ['trend']]);
});

// ── Driver Utilisation ──

it('returns driver utilisation report', function () {
    [, $token] = reportAdminToken();

    $driver = User::factory()->create(['type' => UserType::Driver]);
    Ride::factory()->completed()->count(5)->create([
        'driver_id' => $driver->id,
        'final_fare_amount' => 4000.00,
        'started_at' => now()->subMinutes(30),
        'completed_at' => now(),
    ]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/driver-utilisation');

    $response->assertOk()
        ->assertJsonStructure([
            'report' => [
                'type',
                'total_active_drivers',
                'total_trips',
                'average_trips_per_driver',
                'total_earnings',
                'average_earnings_per_driver',
                'average_trip_duration_minutes',
                'top_drivers',
                'generated_at',
            ],
        ])
        ->assertJsonPath('report.type', 'driver_utilisation')
        ->assertJsonPath('report.total_trips', 5)
        ->assertJsonPath('report.total_active_drivers', 1);
});

it('returns top drivers sorted by trip count', function () {
    [, $token] = reportAdminToken();

    $topDriver = User::factory()->create(['type' => UserType::Driver]);
    $otherDriver = User::factory()->create(['type' => UserType::Driver]);

    Ride::factory()->completed()->count(5)->create(['driver_id' => $topDriver->id]);
    Ride::factory()->completed()->count(2)->create(['driver_id' => $otherDriver->id]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/driver-utilisation');

    $response->assertOk();
    $topDrivers = $response->json('report.top_drivers');
    expect($topDrivers[0]['driver_id'])->toBe($topDriver->id)
        ->and($topDrivers[0]['trip_count'])->toBe(5);
});

// ── Export ──

it('exports ride volume as CSV', function () {
    [, $token] = reportAdminToken();

    Ride::factory()->completed()->count(3)->create();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/export?type=ride_volume&from='.now()->subMonth()->toDateString().'&to='.now()->toDateString());

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('exports revenue as CSV', function () {
    [, $token] = reportAdminToken();

    Ride::factory()->completed()->count(2)->create(['final_fare_amount' => 5000]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/export?type=revenue&from='.now()->subMonth()->toDateString().'&to='.now()->toDateString());

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('exports driver utilisation as CSV', function () {
    [, $token] = reportAdminToken();

    Ride::factory()->completed()->count(2)->create();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/export?type=driver_utilisation&from='.now()->subMonth()->toDateString().'&to='.now()->toDateString());

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('requires type parameter for export', function () {
    [, $token] = reportAdminToken();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/export?from='.now()->subMonth()->toDateString().'&to='.now()->toDateString());

    $response->assertUnprocessable();
});

it('requires date range for export', function () {
    [, $token] = reportAdminToken();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/export?type=ride_volume');

    $response->assertUnprocessable();
});

// ── RBAC ──

it('denies access to non-admin users', function () {
    $user = User::factory()->create(['type' => UserType::Passenger]);
    $token = $user->createToken('auth')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/admin/reports/ride-volume')
        ->assertForbidden();
});

it('allows operations admin access', function () {
    [, $token] = reportAdminToken(AdminRole::Operations);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/ride-volume');

    $response->assertOk();
});

it('allows finance admin access', function () {
    [, $token] = reportAdminToken(AdminRole::Finance);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/reports/revenue');

    $response->assertOk();
});

it('denies support admin access', function () {
    [, $token] = reportAdminToken(AdminRole::Support);

    $this->withToken($token)
        ->getJson('/api/v1/admin/reports/ride-volume')
        ->assertForbidden();
});
