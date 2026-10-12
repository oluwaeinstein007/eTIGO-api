<?php

use App\Models\SavedPlace;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('auth')->plainTextToken;
});

it('lists saved places for the authenticated user', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'home',
        'name' => 'Home',
        'address' => '12 Ahmadu Bello Way, Victoria Island',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
    ]);

    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'work',
        'name' => 'Work',
        'address' => 'Eko Atlantic City',
        'latitude' => 6.4100,
        'longitude' => 3.4050,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/passenger/saved-places');

    $response->assertOk()
        ->assertJsonCount(2, 'saved_places')
        ->assertJsonPath('saved_places.0.type', 'home')
        ->assertJsonPath('saved_places.1.type', 'work');
});

it('stores a home address', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/passenger/saved-places', [
            'type' => 'home',
            'address' => '12 Ahmadu Bello Way, Victoria Island',
            'latitude' => 6.4281,
            'longitude' => 3.4219,
        ]);

    $response->assertCreated()
        ->assertJsonPath('saved_place.type', 'home')
        ->assertJsonPath('saved_place.name', 'Home')
        ->assertJsonPath('saved_place.address', '12 Ahmadu Bello Way, Victoria Island');

    $this->assertDatabaseHas('saved_places', [
        'user_id' => $this->user->id,
        'type' => 'home',
    ]);
});

it('updates existing home address when saving again', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'home',
        'name' => 'Home',
        'address' => 'Old address',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/passenger/saved-places', [
            'type' => 'home',
            'address' => 'New home address',
            'latitude' => 6.5000,
            'longitude' => 3.5000,
        ]);

    $response->assertOk()
        ->assertJsonPath('saved_place.address', 'New home address')
        ->assertJsonPath('message', 'Home address updated.');

    expect($this->user->savedPlaces()->where('type', 'home')->count())->toBe(1);
});

it('stores a custom place with a name', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/passenger/saved-places', [
            'type' => 'custom',
            'name' => 'Gym',
            'address' => 'Eko Hotel & Suites, Adetokunbo Ademola St',
            'latitude' => 6.4310,
            'longitude' => 3.4200,
        ]);

    $response->assertCreated()
        ->assertJsonPath('saved_place.type', 'custom')
        ->assertJsonPath('saved_place.name', 'Gym');
});

it('requires a name for custom places', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/passenger/saved-places', [
            'type' => 'custom',
            'address' => 'Some address',
            'latitude' => 6.4310,
            'longitude' => 3.4200,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('allows multiple custom places', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'custom',
        'name' => 'Gym',
        'address' => 'Address 1',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/passenger/saved-places', [
            'type' => 'custom',
            'name' => 'Church',
            'address' => 'Address 2',
            'latitude' => 6.5000,
            'longitude' => 3.5000,
        ]);

    $response->assertCreated();
    expect($this->user->savedPlaces()->where('type', 'custom')->count())->toBe(2);
});

it('updates a saved place', function () {
    $place = SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'custom',
        'name' => 'Gym',
        'address' => 'Old gym address',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
    ]);

    $response = $this->withToken($this->token)
        ->putJson("/api/v1/passenger/saved-places/{$place->id}", [
            'name' => 'New Gym',
            'address' => 'New gym address',
            'latitude' => 6.5000,
            'longitude' => 3.5000,
        ]);

    $response->assertOk()
        ->assertJsonPath('saved_place.name', 'New Gym')
        ->assertJsonPath('saved_place.address', 'New gym address');
});

it('deletes a saved place', function () {
    $place = SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'custom',
        'name' => 'Gym',
        'address' => 'Some address',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
    ]);

    $response = $this->withToken($this->token)
        ->deleteJson("/api/v1/passenger/saved-places/{$place->id}");

    $response->assertOk()
        ->assertJsonPath('message', 'Place removed.');

    $this->assertDatabaseMissing('saved_places', ['id' => $place->id]);
});

it('prevents accessing another user saved place', function () {
    $otherUser = User::factory()->create();
    $place = SavedPlace::create([
        'user_id' => $otherUser->id,
        'type' => 'home',
        'name' => 'Home',
        'address' => 'Other user home',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
    ]);

    $this->withToken($this->token)
        ->getJson("/api/v1/passenger/saved-places/{$place->id}")
        ->assertNotFound();

    $this->withToken($this->token)
        ->putJson("/api/v1/passenger/saved-places/{$place->id}", ['name' => 'Hacked'])
        ->assertNotFound();

    $this->withToken($this->token)
        ->deleteJson("/api/v1/passenger/saved-places/{$place->id}")
        ->assertNotFound();
});

it('validates required fields on store', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/passenger/saved-places', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'address', 'latitude', 'longitude']);
});

it('rejects invalid type', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/passenger/saved-places', [
            'type' => 'invalid',
            'address' => 'Some address',
            'latitude' => 6.4281,
            'longitude' => 3.4219,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});
