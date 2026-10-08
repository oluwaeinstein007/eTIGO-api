<?php

use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Carbon;

it('returns two-hour earnings for the selected date', function () {
    $this->travelTo('2026-10-08 12:00:00');
    $driver = Driver::factory()->create();
    $otherDriver = Driver::factory()->create();

    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'completed_at' => now()->startOfDay()->addHours(10)->addMinutes(10),
        'final_fare_amount' => 1250,
    ]);
    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'completed_at' => now()->startOfDay()->addHours(10)->addMinutes(45),
        'final_fare_amount' => 2750,
    ]);
    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'completed_at' => now()->startOfDay()->addHours(11),
        'final_fare_amount' => 900,
    ]);
    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'completed_at' => now()->subDay(),
        'final_fare_amount' => 9000,
    ]);
    Ride::factory()->completed()->create([
        'driver_id' => $otherDriver->user_id,
        'completed_at' => now()->startOfDay()->addHours(10),
        'final_fare_amount' => 8000,
    ]);
    Ride::factory()->inProgress()->create([
        'driver_id' => $driver->user_id,
        'final_fare_amount' => 7000,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/driver/earnings?period=today&date=2026-10-08');

    $response->assertOk()
        ->assertJsonPath('earnings.period', 'today')
        ->assertJsonPath('earnings.currency', 'NGN')
        ->assertJsonPath('earnings.total', 4900)
        ->assertJsonPath('earnings.completed_rides', 3)
        ->assertJsonPath('earnings.chart.5.label', '10 AM')
        ->assertJsonPath('earnings.chart.5.amount', 4900)
        ->assertJsonCount(12, 'earnings.chart');
});

it('returns one earnings bucket per day for the week containing the selected date', function () {
    $this->travelTo('2026-10-08 12:00:00');
    $driver = Driver::factory()->create();
    $otherDriver = Driver::factory()->create();
    $weekStart = Carbon::parse('2026-09-30')->startOfWeek(Carbon::MONDAY);

    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'completed_at' => $weekStart->copy()->addHours(9),
        'final_fare_amount' => 1000,
    ]);
    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'completed_at' => $weekStart->copy()->addDays(3)->addHours(15),
        'final_fare_amount' => 2500,
    ]);
    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'completed_at' => $weekStart->copy()->subDay(),
        'final_fare_amount' => 9000,
    ]);
    Ride::factory()->completed()->create([
        'driver_id' => $otherDriver->user_id,
        'completed_at' => $weekStart->copy()->addHours(10),
        'final_fare_amount' => 8000,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/driver/earnings?period=week&date=2026-09-30');

    $response->assertOk()
        ->assertJsonPath('earnings.period', 'week')
        ->assertJsonPath('earnings.total', 3500)
        ->assertJsonPath('earnings.chart.0.label', 'Mon')
        ->assertJsonPath('earnings.chart.0.amount', 1000)
        ->assertJsonPath('earnings.chart.3.amount', 2500)
        ->assertJsonCount(7, 'earnings.chart');
});

it('returns one earnings bucket per day in the selected month', function () {
    $this->travelTo('2026-10-08 12:00:00');
    $driver = Driver::factory()->create();

    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'completed_at' => now()->startOfMonth()->subMonth()->addHours(8),
        'final_fare_amount' => 1800,
    ]);
    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'completed_at' => now()->startOfMonth()->subMonth()->addDays(6)->addHours(16),
        'final_fare_amount' => 3200,
    ]);
    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'completed_at' => now()->startOfMonth()->subMonth()->subSecond(),
        'final_fare_amount' => 9000,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/driver/earnings?period=month&month=2026-09');

    $response->assertOk()
        ->assertJsonPath('earnings.period', 'month')
        ->assertJsonPath('earnings.total', 5000)
        ->assertJsonPath('earnings.chart.0.label', '1')
        ->assertJsonPath('earnings.chart.0.amount', 1800)
        ->assertJsonPath('earnings.chart.6.amount', 3200)
        ->assertJsonCount(30, 'earnings.chart');
});

it('defaults date and month filters and rejects invalid filter values', function () {
    $this->travelTo('2026-10-08 12:00:00');
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/driver/earnings?period=today')
        ->assertOk()
        ->assertJsonPath('earnings.range.start', '2026-10-08T00:00:00+00:00')
        ->assertJsonCount(12, 'earnings.chart');

    $this->withToken($token)
        ->getJson('/api/v1/driver/earnings?period=week&date=not-a-date')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    $this->withToken($token)
        ->getJson('/api/v1/driver/earnings?period=month&month=2026-13')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('month');
});

it('returns 422 when the earnings period is unsupported', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/driver/earnings?period=year');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('period');
});

it('returns 401 when no token is provided', function () {
    $this->getJson('/api/v1/driver/earnings?period=today')->assertUnauthorized();
});

it('forbids passengers from viewing driver earnings', function () {
    $passenger = User::factory()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/driver/earnings?period=today')
        ->assertForbidden();
});
