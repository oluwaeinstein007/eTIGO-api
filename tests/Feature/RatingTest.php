<?php

use App\Enums\UserType;
use App\Models\Rating;
use App\Models\Ride;
use App\Models\User;

beforeEach(function () {
    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->completedRide = Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
    ]);
});

describe('POST /rides/{ride}/rating', function () {
    it('allows passenger to rate a completed ride', function () {
        $response = $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/rating",
            ['score' => 5, 'comment' => 'Great ride!'],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(201)
            ->assertJsonPath('rating.score', 5)
            ->assertJsonPath('rating.comment', 'Great ride!')
            ->assertJsonPath('message', 'Rating submitted successfully.');

        $this->assertDatabaseHas('ratings', [
            'ride_id' => $this->completedRide->id,
            'rated_by_user_id' => $this->passenger->id,
            'rated_user_id' => $this->driverUser->id,
            'score' => 5,
        ]);
    });

    it('allows driver to rate a completed ride', function () {
        $response = $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/rating",
            ['score' => 4],
            ['Authorization' => "Bearer {$this->driverToken}"],
        );

        $response->assertStatus(201)
            ->assertJsonPath('rating.score', 4);

        $this->assertDatabaseHas('ratings', [
            'ride_id' => $this->completedRide->id,
            'rated_by_user_id' => $this->driverUser->id,
            'rated_user_id' => $this->passenger->id,
            'score' => 4,
        ]);
    });

    it('rejects rating for non-completed ride', function () {
        $ride = Ride::factory()->inProgress()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $response = $this->postJson(
            "/api/v1/rides/{$ride->id}/rating",
            ['score' => 5],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('ride');
    });

    it('prevents duplicate rating from same user', function () {
        Rating::create([
            'ride_id' => $this->completedRide->id,
            'rated_by_user_id' => $this->passenger->id,
            'rated_user_id' => $this->driverUser->id,
            'score' => 4,
            'created_at' => now(),
        ]);

        $response = $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/rating",
            ['score' => 5],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('ride');
    });

    it('rejects non-participant from rating', function () {
        $otherUser = User::factory()->create(['type' => UserType::Passenger]);
        $otherToken = $otherUser->createToken('auth', ['passenger'])->plainTextToken;

        $response = $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/rating",
            ['score' => 5],
            ['Authorization' => "Bearer {$otherToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('ride');
    });

    it('validates score is between 1 and 5', function () {
        $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/rating",
            ['score' => 6],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        )->assertStatus(422)->assertJsonValidationErrors('score');

        $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/rating",
            ['score' => 0],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        )->assertStatus(422)->assertJsonValidationErrors('score');
    });

    it('requires authentication', function () {
        $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/rating",
            ['score' => 5],
        )->assertStatus(401);
    });

    it('validates comment max length', function () {
        $response = $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/rating",
            ['score' => 5, 'comment' => str_repeat('a', 1001)],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('comment');
    });
});

describe('User average rating', function () {
    it('calculates average rating for a user', function () {
        $ride1 = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $ride2 = Ride::factory()->completed()->create([
            'passenger_id' => User::factory()->create(['type' => UserType::Passenger])->id,
            'driver_id' => $this->driverUser->id,
        ]);

        Rating::create([
            'ride_id' => $ride1->id,
            'rated_by_user_id' => $this->passenger->id,
            'rated_user_id' => $this->driverUser->id,
            'score' => 5,
            'created_at' => now(),
        ]);

        Rating::create([
            'ride_id' => $ride2->id,
            'rated_by_user_id' => $ride2->passenger_id,
            'rated_user_id' => $this->driverUser->id,
            'score' => 3,
            'created_at' => now(),
        ]);

        expect($this->driverUser->averageRating())->toBe(4.0);
    });

    it('returns null when no ratings exist', function () {
        expect($this->driverUser->averageRating())->toBeNull();
    });
});
