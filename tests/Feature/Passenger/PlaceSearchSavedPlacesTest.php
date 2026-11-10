<?php

use App\Contracts\PlaceSearchGateway;
use App\Models\SavedPlace;
use App\Models\User;

function mockPlaceGateway(array $results = []): void
{
    $gateway = Mockery::mock(PlaceSearchGateway::class);
    $gateway->shouldReceive('searchPlaces')->andReturn($results);
    app()->instance(PlaceSearchGateway::class, $gateway);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('auth')->plainTextToken;
});

it('surfaces a saved place matching the query by name', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'custom',
        'name' => 'My abode',
        'address' => 'Ojoo/Ajibode/Laniba, Nigeria',
        'latitude' => 7.4451,
        'longitude' => 3.9077,
    ]);

    mockPlaceGateway();

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/places/search?q=My+abode');

    $response->assertOk()
        ->assertJsonCount(1, 'places')
        ->assertJsonPath('places.0.name', 'My abode')
        ->assertJsonPath('places.0.source', 'saved_place')
        ->assertJsonPath('places.0.saved_place_type', 'custom');
});

it('surfaces a saved place matching the query by address only', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'custom',
        'name' => 'My abode',
        'address' => 'Ojoo/Ajibode/Laniba, Nigeria',
        'latitude' => 7.4451,
        'longitude' => 3.9077,
    ]);

    mockPlaceGateway();

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/places/search?q=Laniba');

    $response->assertOk()
        ->assertJsonPath('places.0.name', 'My abode')
        ->assertJsonPath('places.0.source', 'saved_place');
});

it('matches saved places case-insensitively', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'home',
        'name' => 'Home',
        'address' => '12 Ahmadu Bello Way, Victoria Island',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
    ]);

    mockPlaceGateway();

    $this->withToken($this->token)
        ->getJson('/api/v1/places/search?q=victoria+ISLAND')
        ->assertJsonPath('places.0.name', 'Home');
});

it('orders saved place matches home before work before custom', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'custom',
        'name' => 'Lagos Gym',
        'address' => 'Lagos Address 1',
        'latitude' => 6.1,
        'longitude' => 3.1,
    ]);
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'home',
        'name' => 'Home',
        'address' => 'Lagos Address 2',
        'latitude' => 6.2,
        'longitude' => 3.2,
    ]);
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'work',
        'name' => 'Work',
        'address' => 'Lagos Address 3',
        'latitude' => 6.3,
        'longitude' => 3.3,
    ]);

    mockPlaceGateway();

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/places/search?q=Lagos');

    $response->assertJsonCount(3, 'places')
        ->assertJsonPath('places.0.saved_place_type', 'home')
        ->assertJsonPath('places.1.saved_place_type', 'work')
        ->assertJsonPath('places.2.saved_place_type', 'custom');
});

it('lists saved place matches ahead of provider search results', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'custom',
        'name' => 'Ikeja Spot',
        'address' => 'Ikeja, Lagos',
        'latitude' => 6.6,
        'longitude' => 3.35,
    ]);

    mockPlaceGateway([
        [
            'id' => 'poi.1',
            'name' => 'Ikeja City Mall',
            'address' => 'Obafemi Awolowo Way, Ikeja, Lagos',
            'latitude' => 6.6190,
            'longitude' => 3.3582,
        ],
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/places/search?q=Ikeja');

    $response->assertJsonCount(2, 'places')
        ->assertJsonPath('places.0.source', 'saved_place')
        ->assertJsonPath('places.1.source', 'search');
});

it('excludes a provider result that duplicates a saved place location', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'home',
        'name' => 'Home',
        'address' => '12 Ahmadu Bello Way, Victoria Island',
        'latitude' => 6.428123,
        'longitude' => 3.421987,
    ]);

    mockPlaceGateway([
        [
            'id' => 'poi.dup',
            'name' => '12 Ahmadu Bello Way',
            'address' => 'Victoria Island, Lagos',
            'latitude' => 6.42812,
            'longitude' => 3.42199,
        ],
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/places/search?q=Ahmadu');

    $response->assertJsonCount(1, 'places')
        ->assertJsonPath('places.0.source', 'saved_place');
});

it('caps the combined result count at the requested limit, prioritising saved places', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'home',
        'name' => 'Lagos Home',
        'address' => 'Lagos Address',
        'latitude' => 6.1,
        'longitude' => 3.1,
    ]);

    mockPlaceGateway([
        ['id' => 'poi.1', 'name' => 'Lagos Place 1', 'address' => 'Lagos', 'latitude' => 6.11, 'longitude' => 3.11],
        ['id' => 'poi.2', 'name' => 'Lagos Place 2', 'address' => 'Lagos', 'latitude' => 6.12, 'longitude' => 3.12],
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/places/search?q=Lagos&limit=1');

    $response->assertJsonCount(1, 'places')
        ->assertJsonPath('places.0.source', 'saved_place');
});

it('treats LIKE wildcard characters in the query as literal text', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'custom',
        'name' => 'A_B Mart',
        'address' => 'Somewhere',
        'latitude' => 6.1,
        'longitude' => 3.1,
    ]);
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'custom',
        'name' => 'AXB Mart',
        'address' => 'Somewhere Else',
        'latitude' => 6.2,
        'longitude' => 3.2,
    ]);

    mockPlaceGateway();

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/places/search?q=A_B');

    $response->assertJsonCount(1, 'places')
        ->assertJsonPath('places.0.name', 'A_B Mart');
});

it('does not match another user saved places', function () {
    $otherUser = User::factory()->create();
    SavedPlace::create([
        'user_id' => $otherUser->id,
        'type' => 'home',
        'name' => 'Home',
        'address' => 'Secret Address, Lagos',
        'latitude' => 6.1,
        'longitude' => 3.1,
    ]);

    mockPlaceGateway();

    $this->withToken($this->token)
        ->getJson('/api/v1/places/search?q=Secret')
        ->assertJsonCount(0, 'places');
});

it('does not surface saved places for drivers', function () {
    $driver = User::factory()->driver()->create();
    $driverToken = $driver->createToken('auth')->plainTextToken;

    SavedPlace::create([
        'user_id' => $driver->id,
        'type' => 'home',
        'name' => 'Home',
        'address' => 'Driver Home Address, Lagos',
        'latitude' => 6.1,
        'longitude' => 3.1,
    ]);

    mockPlaceGateway();

    $this->withToken($driverToken)
        ->getJson('/api/v1/places/search?q=Driver')
        ->assertJsonCount(0, 'places');
});

it('rejects a whitespace-only query before any saved place matching happens', function () {
    SavedPlace::create([
        'user_id' => $this->user->id,
        'type' => 'home',
        'name' => 'Home',
        'address' => 'Any Address, Lagos',
        'latitude' => 6.1,
        'longitude' => 3.1,
    ]);

    mockPlaceGateway();

    $this->withToken($this->token)
        ->getJson('/api/v1/places/search?q=%20%20')
        ->assertUnprocessable();
});
