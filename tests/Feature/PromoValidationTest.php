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
        ->assertJsonPath('promo.code', 'VALID20')
        ->assertJsonPath('promo.discount_type', $promo->discount_type->value)
        ->assertJsonPath('promo.discount_value', $promo->discount_value);
});

it('returns promo details with discount info for frontend display', function () {
    PromoCode::factory()->create([
        'code' => 'DISPLAY50',
        'discount_type' => 'percentage',
        'discount_value' => 50,
        'max_discount_cap' => 1000,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'DISPLAY50',
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('promo.discount_type', 'percentage')
        ->assertJsonPath('promo.discount_value', '50.00')
        ->assertJsonPath('promo.max_discount_cap', '1000.00')
        ->assertJsonMissing(['discount_preview']);
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

it('accepts city-restricted promo at validation time (context checked at apply)', function () {
    $city = City::factory()->create();
    PromoCode::factory()->create([
        'code' => 'CITYONLY',
        'city_id' => $city->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'CITYONLY',
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('promo.city_id', $city->id);
});

it('accepts vehicle-class-restricted promo at validation time', function () {
    $vc = VehicleClass::factory()->create();
    PromoCode::factory()->create([
        'code' => 'VCONLY',
        'vehicle_class_id' => $vc->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'VCONLY',
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('promo.vehicle_class_id', $vc->id);
});

it('accepts geo-fenced promo at validation time', function () {
    PromoCode::factory()->create([
        'code' => 'GEOPROMO',
        'geo_fence' => ['center_lat' => 9.0, 'center_lng' => 7.5, 'radius_km' => 10],
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'GEOPROMO',
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true);
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

it('includes city-specific promos when city_id provided', function () {
    $city = City::factory()->create();
    PromoCode::factory()->create(['code' => 'UNIVERSAL']);
    PromoCode::factory()->create(['code' => 'CITYPROMO', 'city_id' => $city->id]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/promos/available?city_id='.$city->id);

    $response->assertOk();
    $codes = collect($response->json('promos'))->pluck('code')->all();
    expect($codes)->toContain('UNIVERSAL')
        ->and($codes)->toContain('CITYPROMO');
});

it('excludes city-specific promos when no city_id provided', function () {
    $city = City::factory()->create();
    PromoCode::factory()->create(['code' => 'UNIVERSAL']);
    PromoCode::factory()->create(['code' => 'CITYPROMO', 'city_id' => $city->id]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/promos/available');

    $response->assertOk();
    $codes = collect($response->json('promos'))->pluck('code')->all();
    expect($codes)->toContain('UNIVERSAL')
        ->and($codes)->not->toContain('CITYPROMO');
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

it('ignores ride-context fields in validate payload', function () {
    PromoCode::factory()->create(['code' => 'SIMPLE']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/promos/validate', [
            'code' => 'SIMPLE',
            'city_id' => '9a1b2c3d-4e5f-6789-0abc-def123456789',
            'fare_amount' => 5000,
        ]);

    $response->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonMissing(['discount_preview']);
});
