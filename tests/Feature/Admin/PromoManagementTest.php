<?php

use App\Models\City;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;
    $this->city = City::factory()->create();
    $this->vehicleClass = VehicleClass::factory()->create();
});

it('creates a percentage promo code', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', [
            'code' => 'WELCOME20',
            'description' => 'Welcome discount for new users',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'max_discount_cap' => 2000,
            'total_redemption_limit' => 500,
            'per_user_limit' => 1,
            'starts_at' => now()->subHour()->toIso8601String(),
            'expires_at' => now()->addMonth()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJson(['message' => 'Promo code created successfully.'])
        ->assertJsonPath('promo.code', 'WELCOME20')
        ->assertJsonPath('promo.discount_type', 'percentage')
        ->assertJsonPath('promo.discount_value', '20.00');

    $this->assertDatabaseHas('promo_codes', [
        'code' => 'WELCOME20',
        'discount_type' => 'percentage',
    ]);

    $this->assertDatabaseHas('audit_logs', ['event' => 'promo_code_created']);
});

it('creates a flat discount promo code', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', [
            'code' => 'FLAT500',
            'discount_type' => 'flat',
            'discount_value' => 500,
            'per_user_limit' => 3,
            'starts_at' => now()->toIso8601String(),
            'expires_at' => now()->addWeek()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('promo.discount_type', 'flat')
        ->assertJsonPath('promo.discount_value', '500.00');
});

it('creates a promo with city and vehicle class restrictions', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', [
            'code' => 'LAGOS10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'starts_at' => now()->toIso8601String(),
            'expires_at' => now()->addMonth()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('promo.city_id', $this->city->id)
        ->assertJsonPath('promo.vehicle_class_id', $this->vehicleClass->id);
});

it('creates a promo with tier and order count restrictions', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', [
            'code' => 'GOLDONLY',
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'max_discount_cap' => 3000,
            'min_tier_level' => 3,
            'max_order_count' => 5,
            'starts_at' => now()->toIso8601String(),
            'expires_at' => now()->addMonth()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('promo.min_tier_level', 3)
        ->assertJsonPath('promo.max_order_count', 5);
});

it('rejects duplicate promo code', function () {
    PromoCode::factory()->create(['code' => 'DUPE']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', [
            'code' => 'DUPE',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'starts_at' => now()->toIso8601String(),
            'expires_at' => now()->addMonth()->toIso8601String(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('rejects percentage discount over 100', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', [
            'code' => 'TOOMUCH',
            'discount_type' => 'percentage',
            'discount_value' => 150,
            'starts_at' => now()->toIso8601String(),
            'expires_at' => now()->addMonth()->toIso8601String(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('discount_value');
});

it('rejects peak_only and off_peak_only together', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/promos', [
            'code' => 'CONFLICT',
            'discount_type' => 'flat',
            'discount_value' => 100,
            'peak_only' => true,
            'off_peak_only' => true,
            'starts_at' => now()->toIso8601String(),
            'expires_at' => now()->addMonth()->toIso8601String(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('peak_only');
});

it('lists promos with pagination', function () {
    PromoCode::factory()->count(5)->create();

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/promos');

    $response->assertOk()
        ->assertJsonCount(5, 'promos')
        ->assertJsonStructure(['promos', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
});

it('filters promos by status', function () {
    PromoCode::factory()->create();
    PromoCode::factory()->expired()->create();
    PromoCode::factory()->inactive()->create();

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/promos?status=active');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

it('searches promos by code', function () {
    PromoCode::factory()->create(['code' => 'FINDME']);
    PromoCode::factory()->create(['code' => 'OTHER']);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/promos?search=FINDME');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('promos.0.code'))->toBe('FINDME');
});

it('shows a single promo', function () {
    $promo = PromoCode::factory()->create();

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/admin/promos/{$promo->id}");

    $response->assertOk()
        ->assertJsonPath('promo.id', $promo->id);
});

it('updates a promo code', function () {
    $promo = PromoCode::factory()->create();

    $response = $this->withToken($this->token)
        ->putJson("/api/v1/admin/promos/{$promo->id}", [
            'discount_value' => 30,
            'description' => 'Updated description',
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Promo code updated successfully.'])
        ->assertJsonPath('promo.discount_value', '30.00');

    $this->assertDatabaseHas('audit_logs', ['event' => 'promo_code_updated']);
});

it('toggles promo status', function () {
    $promo = PromoCode::factory()->create(['is_active' => true]);

    $response = $this->withToken($this->token)
        ->patchJson("/api/v1/admin/promos/{$promo->id}/status");

    $response->assertOk()
        ->assertJson(['message' => 'Promo code deactivated.']);

    expect($promo->fresh()->is_active)->toBeFalse();

    $this->assertDatabaseHas('audit_logs', ['event' => 'promo_code_toggled']);
});

it('returns promo performance analytics', function () {
    $promo = PromoCode::factory()->create();
    $passenger = User::factory()->create(['type' => 'passenger']);

    PromoRedemption::factory()->count(3)->create([
        'promo_code_id' => $promo->id,
        'user_id' => $passenger->id,
        'discount_amount' => 500,
    ]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/admin/promos/{$promo->id}/performance");

    $response->assertOk()
        ->assertJsonStructure([
            'promo',
            'performance' => [
                'total_redemptions',
                'unique_users',
                'total_discount_given',
                'average_discount',
                'remaining_redemptions',
                'redemptions_by_day',
            ],
            'recent_redemptions',
        ]);

    expect($response->json('performance.total_redemptions'))->toBe(3);
    expect($response->json('performance.unique_users'))->toBe(1);
    expect((float) $response->json('performance.total_discount_given'))->toBe(1500.0);
});

it('prevents non-admin access', function () {
    $passenger = User::factory()->create(['type' => 'passenger']);
    $token = $passenger->createToken('auth', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/promos');

    $response->assertForbidden();
});
