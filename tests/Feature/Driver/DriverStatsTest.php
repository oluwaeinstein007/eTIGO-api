<?php

use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('returns completed trip count and average rating for the authenticated driver', function () {
    $driver = Driver::factory()->create();
    $otherDriver = Driver::factory()->create();
    $completedRides = Ride::factory()
        ->count(2)
        ->completed()
        ->sequence(fn () => ['driver_id' => $driver->user_id])
        ->create();
    Ride::factory()->inProgress()->create(['driver_id' => $driver->user_id]);
    Ride::factory()->completed()->create(['driver_id' => $otherDriver->user_id]);

    foreach ($completedRides as $index => $ride) {
        DB::table('ratings')->insert([
            'id' => (string) Str::uuid(),
            'ride_id' => $ride->id,
            'rated_by_user_id' => $ride->passenger_id,
            'rated_user_id' => $driver->user_id,
            'score' => $index === 0 ? 4 : 5,
            'created_at' => now(),
        ]);
    }
    DB::table('ratings')->insert([
        'id' => (string) Str::uuid(),
        'ride_id' => $completedRides[0]->id,
        'rated_by_user_id' => $driver->user_id,
        'rated_user_id' => $completedRides[0]->passenger_id,
        'score' => 1,
        'created_at' => now(),
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/driver/stats');

    $response->assertOk()->assertJsonPath('stats.completed_trips', 2)
        ->assertJsonPath('stats.average_rating', 4.5)
        ->assertJsonPath('stats.ratings_count', 2);
});

it('returns null average rating when the driver has no ratings', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/driver/stats');

    $response->assertOk()->assertJsonPath('stats.completed_trips', 0)
        ->assertJsonPath('stats.average_rating', null)
        ->assertJsonPath('stats.ratings_count', 0);
});

it('requires authentication to view driver stats', function () {
    $response = $this->getJson('/api/v1/driver/stats');

    $response->assertUnauthorized();
});

it('forbids passengers from viewing driver stats', function () {
    $passenger = User::factory()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/driver/stats');

    $response->assertForbidden();
});
