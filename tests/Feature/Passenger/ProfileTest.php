<?php

use App\Models\User;

it('returns passenger profile', function () {
    $passenger = User::factory()->passenger()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/passenger/profile');

    $response->assertOk()
        ->assertJsonStructure(['user' => ['id', 'first_name', 'last_name', 'phone', 'type']]);
});

it('allows passenger to update their profile', function () {
    $passenger = User::factory()->passenger()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->putJson('/api/v1/passenger/profile', [
            'first_name' => 'Updated',
            'last_name' => 'Name',
        ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'Profile updated successfully.',
            'user' => [
                'first_name' => 'Updated',
                'last_name' => 'Name',
            ],
        ]);
});

it('prevents driver from accessing passenger routes', function () {
    $driver = User::factory()->driver()->create();
    $token = $driver->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/passenger/profile');

    $response->assertForbidden();
});
