<?php

use App\Models\City;
use App\Models\SurgeRule;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\SurgePricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        ->assertJsonPath('surge.multiplier', 1.8)
        ->assertJsonStructure(['demand' => ['requests', 'available_drivers', 'ratio', 'window_minutes']]);
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

// === FRONTEND REPORT 29 OCT: CONDITIONS SURGE PRICING READS ===

it('refuses condition keys surge pricing does not read, such as the old seeded shape', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'name' => 'Old shape',
            'type' => 'time_based',
            'multiplier' => 1.5,
            'conditions' => ['days' => ['monday'], 'days_of_week' => [1], 'start_time' => '07:00', 'end_time' => '09:00'],
            'effective_from' => now()->toIso8601String(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['conditions' => 'Unknown condition.']);

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'name' => 'Old demand',
            'type' => 'demand_based',
            'multiplier' => 1.5,
            'conditions' => ['min_pending_rides' => 15, 'min_utilization_pct' => 85],
            'effective_from' => now()->toIso8601String(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['conditions', 'conditions.min_demand_supply_ratio']);
});

it('keeps only the conditions a rule\'s type reads, with days sorted', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'name' => 'Weekend nights',
            'type' => 'time_based',
            'multiplier' => 1.25,
            'conditions' => ['days_of_week' => ['7', 5, 6], 'start_time' => '20:00', 'end_time' => '00:00', 'min_requests' => 3],
            'effective_from' => now()->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('surge_rule.conditions.days_of_week', [5, 6, 7])
        ->assertJsonPath('surge_rule.conditions.end_time', '00:00')
        ->assertJsonMissingPath('surge_rule.conditions.min_requests');
});

it('refuses a schedule that starts and ends at the same time, or repeats a day', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/admin/surge-rules', [
            'city_id' => $this->city->id,
            'name' => 'Never',
            'type' => 'time_based',
            'multiplier' => 1.25,
            'conditions' => ['days_of_week' => [1, 1], 'start_time' => '07:00', 'end_time' => '07:00'],
            'effective_from' => now()->toIso8601String(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['conditions.end_time', 'conditions.days_of_week.0']);
});

it('needs complete conditions for the rule\'s type when they are replaced', function () {
    $rule = SurgeRule::factory()->timeBased()->create(['city_id' => $this->city->id]);

    // Conditions replace the stored ones, so half a schedule is refused.
    $this->withToken($this->token)
        ->putJson("/api/v1/admin/surge-rules/{$rule->id}", ['conditions' => ['start_time' => '06:00']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['conditions.days_of_week', 'conditions.end_time']);

    // Other edits leave the conditions alone.
    $this->withToken($this->token)
        ->putJson("/api/v1/admin/surge-rules/{$rule->id}", ['multiplier' => 1.8])
        ->assertOk()
        ->assertJsonPath('surge_rule.conditions.start_time', '07:00');

    // A new type needs its own conditions, and drops the old ones.
    $this->withToken($this->token)
        ->putJson("/api/v1/admin/surge-rules/{$rule->id}", ['type' => 'demand_based'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('conditions.min_demand_supply_ratio');

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/surge-rules/{$rule->id}", ['type' => 'demand_based', 'conditions' => ['min_demand_supply_ratio' => 1.5]])
        ->assertOk()
        ->assertJsonPath('surge_rule.conditions', ['min_demand_supply_ratio' => 1.5]);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/surge-rules/{$rule->id}", ['type' => 'manual'])
        ->assertOk()
        ->assertJsonPath('surge_rule.conditions', []);
});

it('checks a new end date against the saved start, and a new start against the saved end', function () {
    $rule = SurgeRule::factory()->create([
        'city_id' => $this->city->id,
        'effective_from' => now()->subDay(),
        'effective_until' => now()->addWeek(),
    ]);

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/surge-rules/{$rule->id}", ['effective_until' => now()->addMonth()->toIso8601String()])
        ->assertOk();

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/surge-rules/{$rule->id}", ['effective_until' => now()->subWeek()->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('effective_until');

    $this->withToken($this->token)
        ->putJson("/api/v1/admin/surge-rules/{$rule->id}", ['effective_from' => now()->addYear()->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('effective_from');
});

it('rewrites rules saved in the old seeded shape so surge pricing reads them', function () {
    $insert = fn (string $type, array $conditions) => tap(SurgeRule::factory()->create(['city_id' => $this->city->id, 'type' => $type]),
        fn (SurgeRule $rule) => DB::table('surge_rules')->where('id', $rule->id)->update(['conditions' => json_encode($conditions)]));

    $weekdays = $insert('time_based', ['days' => ['monday', 'Tuesday', 'wed', 'thursday', 'friday'], 'start_time' => '07:00', 'end_time' => '09:30']);
    $demand = $insert('demand_based', ['min_pending_rides' => 15, 'min_utilization_pct' => 85]);
    $current = $insert('time_based', ['days_of_week' => [6, 7], 'start_time' => '10:00', 'end_time' => '12:00']);
    $manual = $insert('manual', []);

    (require database_path('migrations/2026_10_29_200000_normalize_legacy_surge_rule_conditions.php'))->up();

    // Key order is the database's (jsonb), so compared as maps.
    expect($weekdays->fresh()->conditions)->toEqual(['days_of_week' => [1, 2, 3, 4, 5], 'start_time' => '07:00', 'end_time' => '09:30'])
        ->and($demand->fresh()->conditions)->toEqual(['min_requests' => 15, 'min_demand_supply_ratio' => 2.0])
        ->and($current->fresh()->conditions)->toEqual(['days_of_week' => [6, 7], 'start_time' => '10:00', 'end_time' => '12:00'])
        ->and($manual->fresh()->conditions)->toBe([]);

    // Monday 08:00 in Lagos is inside the weekday rule now.
    $weekdays->update(['effective_from' => now()->subYear()]);
    $this->travelTo(Carbon::parse('2026-10-19 07:00:00', 'UTC'));
    $demand->update(['is_active' => false]);
    $current->update(['is_active' => false]);
    $manual->update(['is_active' => false]);
    $surge = app(SurgePricingService::class)->getCurrentMultiplier($this->city->id);
    expect($surge['rule']?->id)->toBe($weekdays->id);
});

it('seeds the demo surge rules in the shape surge pricing reads', function () {
    $source = file_get_contents(database_path('seeders/DemoSeeder.php'));

    expect($source)->not->toContain("'days' =>")
        ->and($source)->not->toContain('min_pending_rides')
        ->and($source)->not->toContain('min_utilization_pct');
});
