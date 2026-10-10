<?php

use App\Enums\RideStatus;
use App\Models\City;
use App\Models\GamificationProfile;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;

beforeEach(function () {
    $this->passenger = User::factory()->create(['type' => 'passenger']);
    $this->token = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;
    $this->city = City::factory()->create();
    $this->vehicleClass = VehicleClass::factory()->create();
});

it('validates a valid promo code', function () {
    $promo = PromoCode::factory()->create(['code' => 'VALID20']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'VALID20',
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('promo.code', 'VALID20');
});

it('returns discount preview when fare_amount provided', function () {
    PromoCode::factory()->create([
        'code' => 'PREVIEW',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'max_discount_cap' => 1000,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'PREVIEW',
            'fare_amount' => 3000,
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('discount_preview.discount_amount', 600);
});

it('caps percentage discount at max_discount_cap', function () {
    PromoCode::factory()->create([
        'code' => 'CAPPED',
        'discount_type' => 'percentage',
        'discount_value' => 50,
        'max_discount_cap' => 500,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'CAPPED',
            'fare_amount' => 5000,
        ]);

    $response->assertOk()
        ->assertJsonPath('discount_preview.discount_amount', 500);
});

it('rejects invalid promo code', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'NONEXISTENT',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('message', 'Invalid promo code.');
});

it('rejects expired promo code', function () {
    PromoCode::factory()->expired()->create(['code' => 'EXPIRED']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'EXPIRED',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false);
});

it('rejects inactive promo code', function () {
    PromoCode::factory()->inactive()->create(['code' => 'INACTIVE']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'INACTIVE',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false);
});

it('rejects promo when user has reached per-user limit', function () {
    $promo = PromoCode::factory()->create([
        'code' => 'ONCE',
        'per_user_limit' => 1,
    ]);

    PromoRedemption::factory()->create([
        'promo_code_id' => $promo->id,
        'user_id' => $this->passenger->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'ONCE',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false);
});

it('rejects promo when global limit reached', function () {
    $promo = PromoCode::factory()->create([
        'code' => 'LIMITED',
        'total_redemption_limit' => 2,
    ]);

    PromoRedemption::factory()->count(2)->create([
        'promo_code_id' => $promo->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'LIMITED',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false);
});

it('rejects promo for wrong city', function () {
    $otherCity = City::factory()->create();
    PromoCode::factory()->create([
        'code' => 'CITYONLY',
        'city_id' => $otherCity->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'CITYONLY',
            'city_id' => $this->city->id,
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('message', 'This promo code is not valid in your city.');
});

it('rejects promo for wrong vehicle class', function () {
    $otherVc = VehicleClass::factory()->create();
    PromoCode::factory()->create([
        'code' => 'VCONLY',
        'vehicle_class_id' => $otherVc->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'VCONLY',
            'vehicle_class_id' => $this->vehicleClass->id,
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false);
});

it('rejects promo when minimum fare not met', function () {
    PromoCode::factory()->create([
        'code' => 'MINFARE',
        'minimum_fare_amount' => 2000,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'MINFARE',
            'fare_amount' => 1000,
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false);
});

it('rejects promo for new users only when user has too many rides', function () {
    $promo = PromoCode::factory()->newUsersOnly(3)->create(['code' => 'NEWUSER']);

    Ride::factory()->count(5)->create([
        'passenger_id' => $this->passenger->id,
        'status' => RideStatus::Completed,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'NEWUSER',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('message', 'This promo code is for new users only.');
});

it('rejects promo when user tier is too low', function () {
    $promo = PromoCode::factory()->tierRestricted(3)->create(['code' => 'GOLDTIER']);

    GamificationProfile::factory()->create([
        'user_id' => $this->passenger->id,
        'current_tier' => 1,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'GOLDTIER',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false);
});

it('accepts promo when user tier meets minimum', function () {
    $promo = PromoCode::factory()->tierRestricted(2)->create(['code' => 'SILVER']);

    GamificationProfile::factory()->create([
        'user_id' => $this->passenger->id,
        'current_tier' => 3,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'SILVER',
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true);
});

it('rejects city-restricted promo when no city context provided', function () {
    $city = City::factory()->create();
    PromoCode::factory()->create([
        'code' => 'CITYNOCTX',
        'city_id' => $city->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'CITYNOCTX',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('message', 'This promo code is not valid in your city.');
});

it('rejects geo-fenced promo when no pickup coords provided', function () {
    PromoCode::factory()->create([
        'code' => 'GEONOCTX',
        'geo_fence' => ['center_lat' => 9.0, 'center_lng' => 7.5, 'radius_km' => 10],
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'GEONOCTX',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('message', 'This promo code is not valid in your area.');
});

it('accepts city-restricted promo when matching city provided', function () {
    $city = City::factory()->create();
    PromoCode::factory()->create([
        'code' => 'CITYOK',
        'city_id' => $city->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'CITYOK',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true);
});

it('shows city-restricted promos in available list via user eligibility', function () {
    $city = City::factory()->create();
    PromoCode::factory()->create(['code' => 'UNIVERSAL']);
    PromoCode::factory()->create(['code' => 'CITYPROMO', 'city_id' => $city->id]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/promos/available');

    $response->assertOk();
    $codes = collect($response->json('promos'))->pluck('code')->all();
    expect($codes)->toContain('UNIVERSAL');
});

it('is case-insensitive on promo code input', function () {
    PromoCode::factory()->create(['code' => 'UPPER']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'upper',
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true);
});

it('returns available promos for user', function () {
    PromoCode::factory()->create(['code' => 'AVAIL1']);
    PromoCode::factory()->create(['code' => 'AVAIL2']);
    PromoCode::factory()->expired()->create(['code' => 'EXPIRED']);
    PromoCode::factory()->inactive()->create(['code' => 'DEAD']);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/promos/available');

    $response->assertOk()
        ->assertJsonCount(2, 'promos');
});

it('filters out already-used promos from available', function () {
    $promo = PromoCode::factory()->create([
        'code' => 'USED',
        'per_user_limit' => 1,
    ]);

    PromoRedemption::factory()->create([
        'promo_code_id' => $promo->id,
        'user_id' => $this->passenger->id,
    ]);

    PromoCode::factory()->create(['code' => 'FRESH']);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/promos/available');

    $response->assertOk();

    $codes = collect($response->json('promos'))->pluck('code')->all();
    expect($codes)->toContain('FRESH');
    expect($codes)->not->toContain('USED');
});
