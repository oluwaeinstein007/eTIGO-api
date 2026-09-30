<?php

use App\Models\City;
use App\Models\SurgeRule;
use App\Models\User;
use App\Models\VehicleClass;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;
    $this->city = City::factory()->create();
    $this->vehicleClass = VehicleClass::factory()->create();
});

it('creates a manual surge rule', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'name' => 'Heavy Rain',
            'type' => 'manual',
            'multiplier' => 1.5,
            'effective_from' => now()->subHour()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJson(['message' => 'Surge rule created successfully.'])
        ->assertJsonPath('surge_rule.name', 'Heavy Rain')
        ->assertJsonPath('surge_rule.type', 'manual')
        ->assertJsonPath('surge_rule.multiplier', '1.50');

    expect($response->json('surge_rule.is_active'))->toBeTrue();

    $this->assertDatabaseHas('surge_rules', [
        'city_id' => $this->city->id,
        'name' => 'Heavy Rain',
        'multiplier' => '1.50',
    ]);

    $this->assertDatabaseHas('audit_logs', ['event' => 'surge_rule_created']);
});

it('creates a time-based surge rule with schedule', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'name' => 'Morning Rush Hour',
            'type' => 'time_based',
            'multiplier' => 1.3,
            'conditions' => [
                'days_of_week' => [1, 2, 3, 4, 5],
                'start_time' => '07:00',
                'end_time' => '09:00',
            ],
            'priority' => 5,
            'effective_from' => now()->subHour()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('surge_rule.type', 'time_based')
        ->assertJsonPath('surge_rule.conditions.start_time', '07:00')
        ->assertJsonPath('surge_rule.conditions.end_time', '09:00')
        ->assertJsonPath('surge_rule.priority', 5);
});

it('creates a demand-based surge rule', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'name' => 'High Demand',
            'type' => 'demand_based',
            'multiplier' => 2.0,
            'conditions' => [
                'min_demand_supply_ratio' => 2.5,
            ],
            'effective_from' => now()->subHour()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('surge_rule.type', 'demand_based')
        ->assertJsonPath('surge_rule.conditions.min_demand_supply_ratio', 2.5);
});

it('creates a surge rule scoped to a vehicle class', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'name' => 'Premium Surge',
            'type' => 'manual',
            'multiplier' => 2.0,
            'effective_from' => now()->subHour()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('surge_rule.vehicle_class_id', $this->vehicleClass->id);
});

it('validates required fields when creating surge rule', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['city_id', 'name', 'type', 'multiplier', 'effective_from']);
});

it('rejects multiplier below 1.0', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'name' => 'Invalid',
            'type' => 'manual',
            'multiplier' => 0.5,
            'effective_from' => now()->toIso8601String(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['multiplier']);
});

it('rejects multiplier above 5.0', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'name' => 'Invalid',
            'type' => 'manual',
            'multiplier' => 6.0,
            'effective_from' => now()->toIso8601String(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['multiplier']);
});

it('rejects invalid surge type', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'name' => 'Invalid',
            'type' => 'invalid_type',
            'multiplier' => 1.5,
            'effective_from' => now()->toIso8601String(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

it('lists surge rules for admin', function () {
    SurgeRule::factory()->count(3)->create([
        'city_id' => $this->city->id,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/surge-rules');

    $response->assertOk()
        ->assertJsonCount(3, 'surge_rules')
        ->assertJsonStructure(['surge_rules', 'meta']);
});

it('filters surge rules by city', function () {
    $otherCity = City::factory()->create();

    SurgeRule::factory()->count(2)->create([
        'city_id' => $this->city->id,
        'created_by_admin_id' => $this->admin->id,
    ]);
    SurgeRule::factory()->create([
        'city_id' => $otherCity->id,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/surge-rules?city_id='.$this->city->id);

    $response->assertOk()
        ->assertJsonCount(2, 'surge_rules');
});

it('shows a specific surge rule', function () {
    $rule = SurgeRule::factory()->create([
        'city_id' => $this->city->id,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/surge-rules/'.$rule->id);

    $response->assertOk()
        ->assertJsonPath('surge_rule.id', $rule->id)
        ->assertJsonStructure([
            'surge_rule' => ['id', 'city_id', 'name', 'type', 'multiplier', 'conditions', 'priority', 'is_active'],
        ]);
});

it('updates a surge rule', function () {
    $rule = SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'name' => 'Old Name',
        'multiplier' => 1.5,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->putJson('/api/v1/admin/surge-rules/'.$rule->id, [
            'name' => 'Updated Name',
            'multiplier' => 2.0,
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Surge rule updated successfully.'])
        ->assertJsonPath('surge_rule.name', 'Updated Name')
        ->assertJsonPath('surge_rule.multiplier', '2.00');

    $this->assertDatabaseHas('surge_rules', [
        'id' => $rule->id,
        'name' => 'Updated Name',
        'multiplier' => '2.00',
    ]);

    $this->assertDatabaseHas('audit_logs', ['event' => 'surge_rule_updated']);
});

it('toggles surge rule status', function () {
    $rule = SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'is_active' => true,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->patchJson('/api/v1/admin/surge-rules/'.$rule->id.'/status');

    $response->assertOk()
        ->assertJson(['message' => 'Surge rule deactivated.'])
        ->assertJsonPath('surge_rule.is_active', false);

    $this->assertDatabaseHas('surge_rules', [
        'id' => $rule->id,
        'is_active' => false,
    ]);

    $this->assertDatabaseHas('audit_logs', ['event' => 'surge_rule_toggled']);
});

it('returns current surge multiplier for city', function () {
    SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.8,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/surge-rules/current-multiplier?city_id='.$this->city->id);

    $response->assertOk()
        ->assertJsonPath('surge.active', true)
        ->assertJsonPath('surge.multiplier', 1.8);
});

it('returns no surge when no rules match', function () {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/surge-rules/current-multiplier?city_id='.$this->city->id);

    $response->assertOk()
        ->assertJsonPath('surge.active', false)
        ->assertJsonPath('surge.multiplier', 1);
});

it('denies non-admin users', function () {
    $passenger = User::factory()->create();
    $token = $passenger->createToken('passenger-auth', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/surge-rules');

    $response->assertForbidden();
});
