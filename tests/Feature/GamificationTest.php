<?php

use App\Enums\AdminRole;
use App\Enums\MultiplierConditionType;
use App\Enums\TierLevel;
use App\Enums\UserType;
use App\Events\TierUpgraded;
use App\Http\Resources\GamificationResource;
use App\Jobs\CalculateCarbonScoreJob;
use App\Models\City;
use App\Models\GamificationProfile;
use App\Models\PointMultiplierConfig;
use App\Models\Ride;
use App\Models\TierConfig;
use App\Models\TripCarbonScore;
use App\Models\User;
use App\Services\CarbonScoreService;
use App\Services\PointMultiplierService;
use App\Services\TierEvaluationService;
use App\Services\TierGateService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->admin = User::factory()->create([
        'type' => UserType::Admin,
        'admin_role' => AdminRole::SuperAdmin,
    ]);
    $this->adminToken = $this->admin->createToken('auth', ['admin', 'super_admin'])->plainTextToken;

    TierConfig::updateOrCreate(['tier_level' => 1], [
        'tier_name' => 'Bronze', 'min_points_required' => 0,
        'booking_fee_discount_pct' => 0, 'ev_reservation_fee_waived' => false, 'priority_matching_enabled' => false,
    ]);
    TierConfig::updateOrCreate(['tier_level' => 2], [
        'tier_name' => 'Silver', 'min_points_required' => 500,
        'booking_fee_discount_pct' => 2, 'ev_reservation_fee_waived' => false, 'priority_matching_enabled' => false,
    ]);
    TierConfig::updateOrCreate(['tier_level' => 3], [
        'tier_name' => 'Gold', 'min_points_required' => 2000,
        'booking_fee_discount_pct' => 5, 'ev_reservation_fee_waived' => false, 'priority_matching_enabled' => true,
    ]);
    TierConfig::updateOrCreate(['tier_level' => 4], [
        'tier_name' => 'Platinum', 'min_points_required' => 5000,
        'booking_fee_discount_pct' => 10, 'ev_reservation_fee_waived' => true, 'priority_matching_enabled' => true,
    ]);
    TierConfig::updateOrCreate(['tier_level' => 5], [
        'tier_name' => 'Diamond', 'min_points_required' => 15000,
        'booking_fee_discount_pct' => 15, 'ev_reservation_fee_waived' => true, 'priority_matching_enabled' => true,
    ]);

    PointMultiplierConfig::updateOrCreate(
        ['condition_type' => MultiplierConditionType::EvRide->value],
        ['multiplier_value' => 2.00, 'is_stackable' => true],
    );
    PointMultiplierConfig::updateOrCreate(
        ['condition_type' => MultiplierConditionType::OffPeak->value],
        ['multiplier_value' => 1.25, 'is_stackable' => true],
    );
    PointMultiplierConfig::updateOrCreate(
        ['condition_type' => MultiplierConditionType::SharedJourney->value],
        ['multiplier_value' => 1.50, 'is_stackable' => true],
    );
});

describe('CarbonScoreService', function () {
    it('calculates carbon score for a completed ride', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'pricing_snapshot' => ['distance_km' => 10.0, 'duration_minutes' => 15],
        ]);
        $ride->load('vehicleClass');

        $service = app(CarbonScoreService::class);
        $result = $service->calculateForRide($ride, $this->passenger->id);

        expect($result['co2_saved_kg'])->toBeGreaterThan(0)
            ->and($result['base_points'])->toBeGreaterThanOrEqual(5)
            ->and($result['trip_carbon_score'])->toBeInstanceOf(TripCarbonScore::class);

        $this->assertDatabaseHas('trip_carbon_scores', [
            'ride_id' => $ride->id,
            'user_id' => $this->passenger->id,
        ]);
    });

    it('awards minimum points for very short trips', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'pricing_snapshot' => ['distance_km' => 0.1, 'duration_minutes' => 1],
        ]);
        $ride->load('vehicleClass');

        $service = app(CarbonScoreService::class);
        $result = $service->calculateForRide($ride, $this->passenger->id);

        expect($result['base_points'])->toBeGreaterThanOrEqual(config('gamification.minimum_points_per_trip'));
    });
});

describe('PointMultiplierService', function () {
    it('applies off-peak multiplier for late-night rides', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'pricing_snapshot' => ['distance_km' => 10.0, 'duration_minutes' => 15],
            'started_at' => now()->setTime(23, 0),
        ]);
        $ride->load('vehicleClass');

        $carbonService = app(CarbonScoreService::class);
        $scoreResult = $carbonService->calculateForRide($ride, $this->passenger->id);

        $multiplierService = app(PointMultiplierService::class);
        $result = $multiplierService->applyMultipliers($scoreResult['trip_carbon_score'], $ride);

        expect($result['multiplier'])->toBeGreaterThan(1.0)
            ->and($result['final_points'])->toBeGreaterThan($scoreResult['base_points']);
    });
});

describe('TierEvaluationService', function () {
    it('promotes user when points exceed tier threshold', function () {
        Event::fake([TierUpgraded::class]);

        $profile = GamificationProfile::create([
            'user_id' => $this->passenger->id,
            'total_carbon_score' => 0,
            'total_ranking_points' => 450,
            'current_tier' => TierLevel::Bronze->value,
        ]);

        $service = app(TierEvaluationService::class);
        $result = $service->evaluateAndPromote($profile, 100, 5.0);

        expect($result['promoted'])->toBeTrue()
            ->and($result['new_tier'])->toBe(TierLevel::Silver->value)
            ->and($result['tier_name'])->toBe('Silver');

        Event::assertDispatched(TierUpgraded::class, function ($event) {
            return $event->newTier === TierLevel::Silver->value;
        });
    });

    it('keeps the promotion when the tier broadcast fails', function () {
        // As when Reverb is down: the broadcast throws after the commit.
        $broadcastTried = false;
        Event::listen(TierUpgraded::class, function () use (&$broadcastTried) {
            $broadcastTried = true;

            throw new RuntimeException('Pusher error: <html>');
        });

        $profile = GamificationProfile::create([
            'user_id' => $this->passenger->id,
            'total_carbon_score' => 0,
            'total_ranking_points' => 450,
            'current_tier' => TierLevel::Bronze->value,
        ]);

        $result = app(TierEvaluationService::class)->evaluateAndPromote($profile, 100, 5.0);

        expect($broadcastTried)->toBeTrue()
            ->and($result['promoted'])->toBeTrue()
            ->and($profile->fresh()->current_tier)->toBe(TierLevel::Silver->value);
    });

    it('does not promote when below threshold', function () {
        Event::fake([TierUpgraded::class]);

        $profile = GamificationProfile::create([
            'user_id' => $this->passenger->id,
            'total_carbon_score' => 0,
            'total_ranking_points' => 100,
            'current_tier' => TierLevel::Bronze->value,
        ]);

        $service = app(TierEvaluationService::class);
        $result = $service->evaluateAndPromote($profile, 50, 2.0);

        expect($result['promoted'])->toBeFalse();
        Event::assertNotDispatched(TierUpgraded::class);
    });
});

describe('TierGateService', function () {
    it('returns correct benefits for platinum tier', function () {
        GamificationProfile::create([
            'user_id' => $this->passenger->id,
            'total_ranking_points' => 6000,
            'current_tier' => TierLevel::Platinum->value,
        ]);

        $gate = app(TierGateService::class);
        $benefits = $gate->getUserBenefits($this->passenger->id);

        expect($benefits['priority_matching'])->toBeTrue()
            ->and($benefits['ev_fee_waived'])->toBeTrue()
            ->and($benefits['booking_fee_discount_pct'])->toBe(10.0);
    });

    it('returns bronze defaults for user without profile', function () {
        $gate = app(TierGateService::class);
        $benefits = $gate->getUserBenefits($this->passenger->id);

        expect($benefits['tier_level'])->toBe(1)
            ->and($benefits['priority_matching'])->toBeFalse()
            ->and($benefits['booking_fee_discount_pct'])->toBe(0.0);
    });

    it('keeps exclusive promotions for a silver user whose tier config is missing', function () {
        GamificationProfile::create([
            'user_id' => $this->passenger->id,
            'total_ranking_points' => 600,
            'current_tier' => TierLevel::Silver->value,
        ]);
        TierConfig::query()->delete();

        $gate = app(TierGateService::class);

        expect($gate->getUserBenefits($this->passenger->id)['exclusive_promotions'])->toBeTrue()
            ->and($gate->qualifiesForTierPromo($this->passenger->id, TierLevel::Silver->value))->toBeTrue();
    });
});

describe('CalculateCarbonScoreJob', function () {
    it('processes ride and creates carbon score', function () {
        Event::fake([TierUpgraded::class]);

        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'pricing_snapshot' => ['distance_km' => 15.0, 'duration_minutes' => 20],
        ]);

        $job = new CalculateCarbonScoreJob($ride->id);
        $job->handle(
            app(CarbonScoreService::class),
            app(PointMultiplierService::class),
            app(TierEvaluationService::class),
        );

        $this->assertDatabaseHas('trip_carbon_scores', [
            'ride_id' => $ride->id,
            'user_id' => $this->passenger->id,
        ]);

        $this->assertDatabaseHas('gamification_profiles', [
            'user_id' => $this->passenger->id,
        ]);

        $profile = GamificationProfile::where('user_id', $this->passenger->id)->first();
        expect($profile->total_ranking_points)->toBeGreaterThan(0)
            ->and($profile->total_carbon_score)->toBeGreaterThan(0);
    });

    it('is idempotent — skips if score already exists', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'pricing_snapshot' => ['distance_km' => 10.0, 'duration_minutes' => 15],
        ]);

        TripCarbonScore::factory()->create([
            'ride_id' => $ride->id,
            'user_id' => $this->passenger->id,
        ]);

        $job = new CalculateCarbonScoreJob($ride->id);
        $job->handle(
            app(CarbonScoreService::class),
            app(PointMultiplierService::class),
            app(TierEvaluationService::class),
        );

        expect(TripCarbonScore::where('ride_id', $ride->id)->count())->toBe(1);
    });
});

describe('GET /gamification', function () {
    it('returns gamification profile for authenticated user', function () {
        $response = $this->getJson('/api/v1/gamification', [
            'Authorization' => "Bearer {$this->passengerToken}",
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'gamification' => [
                    'id', 'user_id', 'current_tier', 'tier_name',
                    'total_ranking_points', 'total_carbon_score',
                    'progress_to_next_tier', 'points_to_next_tier', 'next_tier',
                ],
            ]);
    });

    it('still returns benefits when the tier config is missing', function () {
        GamificationProfile::create([
            'user_id' => $this->passenger->id,
            'total_ranking_points' => 600,
            'current_tier' => TierLevel::Silver->value,
        ]);
        TierConfig::query()->delete();
        $forgetCachedTiers = Closure::bind(static fn () => self::$tierConfigCache = null, null, GamificationResource::class);

        $forgetCachedTiers();
        $response = $this->getJson('/api/v1/gamification', [
            'Authorization' => "Bearer {$this->passengerToken}",
        ]);
        $forgetCachedTiers();

        $response->assertOk()
            ->assertJsonPath('gamification.benefits.booking_fee_discount_pct', 0)
            ->assertJsonPath('gamification.benefits.priority_matching', false)
            ->assertJsonPath('gamification.benefits.exclusive_promotions', true);
    });
});

describe('GET /gamification/tiers', function () {
    it('returns all tier configurations', function () {
        $response = $this->getJson('/api/v1/gamification/tiers', [
            'Authorization' => "Bearer {$this->passengerToken}",
        ]);

        $response->assertOk()
            ->assertJsonCount(5, 'tiers');
    });
});

describe('GET /gamification/leaderboard', function () {
    it('returns leaderboard sorted by points', function () {
        GamificationProfile::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/gamification/leaderboard', [
            'Authorization' => "Bearer {$this->passengerToken}",
        ]);

        $response->assertOk()
            ->assertJsonStructure(['leaderboard']);
    });
});

describe('Admin Gamification', function () {
    it('lists gamification users', function () {
        GamificationProfile::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/admin/gamification/users', [
            'Authorization' => "Bearer {$this->adminToken}",
        ]);

        $response->assertOk()
            ->assertJsonStructure(['profiles', 'meta']);
    });

    it('lists only passengers, each with the city of their latest ride', function () {
        $ibadan = City::factory()->create(['name' => 'Ibadan']);
        $lagos = City::factory()->create(['name' => 'Lagos']);
        $passenger = User::factory()->passenger()->create();
        GamificationProfile::factory()->create(['user_id' => $passenger->id]);
        Ride::factory()->create(['passenger_id' => $passenger->id, 'city_id' => $lagos->id, 'created_at' => now()->subWeek()]);
        Ride::factory()->create(['passenger_id' => $passenger->id, 'city_id' => $ibadan->id, 'created_at' => now()->subDay()]);
        GamificationProfile::factory()->create(['user_id' => User::factory()->driver()->create()->id]);

        $response = $this->getJson('/api/v1/admin/gamification/users', [
            'Authorization' => "Bearer {$this->adminToken}",
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'profiles')
            ->assertJsonPath('profiles.0.user_id', $passenger->id)
            ->assertJsonPath('profiles.0.city_id', $ibadan->id)
            ->assertJsonPath('profiles.0.city_name', 'Ibadan');
    });

    it('leaves drivers out of the aggregate', function () {
        GamificationProfile::factory()->create(['user_id' => User::factory()->passenger()->create()->id]);
        GamificationProfile::factory()->create(['user_id' => User::factory()->driver()->create()->id]);

        $response = $this->getJson('/api/v1/admin/gamification/aggregate', [
            'Authorization' => "Bearer {$this->adminToken}",
        ]);

        $response->assertOk()->assertJsonPath('aggregate.total_profiles', 1);
    });

    it('returns aggregate stats', function () {
        GamificationProfile::factory()->count(5)->create();

        $response = $this->getJson('/api/v1/admin/gamification/aggregate', [
            'Authorization' => "Bearer {$this->adminToken}",
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'aggregate' => [
                    'total_profiles', 'average_carbon_score', 'average_ranking_points',
                    'total_co2_saved_kg', 'tier_distribution', 'top_users',
                ],
            ]);
    });

    it('updates tier configuration', function () {
        $response = $this->putJson('/api/v1/admin/gamification/tiers', [
            'tiers' => [
                ['tier_level' => 1, 'tier_name' => 'Bronze', 'min_points_required' => 0, 'booking_fee_discount_pct' => 0, 'ev_reservation_fee_waived' => false, 'priority_matching_enabled' => false],
                ['tier_level' => 2, 'tier_name' => 'Silver', 'min_points_required' => 600, 'booking_fee_discount_pct' => 3, 'ev_reservation_fee_waived' => false, 'priority_matching_enabled' => false],
                ['tier_level' => 3, 'tier_name' => 'Gold', 'min_points_required' => 2500, 'booking_fee_discount_pct' => 6, 'ev_reservation_fee_waived' => false, 'priority_matching_enabled' => true],
                ['tier_level' => 4, 'tier_name' => 'Platinum', 'min_points_required' => 6000, 'booking_fee_discount_pct' => 12, 'ev_reservation_fee_waived' => true, 'priority_matching_enabled' => true],
                ['tier_level' => 5, 'tier_name' => 'Diamond', 'min_points_required' => 18000, 'booking_fee_discount_pct' => 18, 'ev_reservation_fee_waived' => true, 'priority_matching_enabled' => true],
            ],
        ], ['Authorization' => "Bearer {$this->adminToken}"]);

        $response->assertOk()
            ->assertJsonPath('message', 'Tier configuration updated successfully.');

        $this->assertDatabaseHas('tier_configs', [
            'tier_level' => 2,
            'min_points_required' => 600,
            'booking_fee_discount_pct' => 3,
        ]);
    });

    it('updates multiplier configuration', function () {
        $response = $this->putJson('/api/v1/admin/gamification/multipliers', [
            'multipliers' => [
                ['condition_type' => 'ev_ride', 'multiplier_value' => 2.50, 'is_stackable' => true],
                ['condition_type' => 'off_peak', 'multiplier_value' => 1.50, 'is_stackable' => true],
            ],
        ], ['Authorization' => "Bearer {$this->adminToken}"]);

        $response->assertOk()
            ->assertJsonPath('message', 'Multiplier configuration updated successfully.');

        $this->assertDatabaseHas('point_multiplier_configs', [
            'condition_type' => 'ev_ride',
            'multiplier_value' => 2.50,
        ]);
    });

    it('accepts multipliers from 1.00x to 3.00x in 0.05x steps', function () {
        $this->putJson('/api/v1/admin/gamification/multipliers', [
            'multipliers' => [
                ['condition_type' => 'ev_ride', 'multiplier_value' => 3.00, 'is_stackable' => true],
                ['condition_type' => 'off_peak', 'multiplier_value' => 1.25, 'is_stackable' => true],
                ['condition_type' => 'shared_journey', 'multiplier_value' => 1.00, 'is_stackable' => true],
            ],
        ], ['Authorization' => "Bearer {$this->adminToken}"])->assertOk();

        expect((float) PointMultiplierConfig::forCondition(MultiplierConditionType::OffPeak)->multiplier_value)->toBe(1.25);
    });

    it('rejects multipliers above 3.00x or off the 0.05x step', function (float $value) {
        $this->putJson('/api/v1/admin/gamification/multipliers', [
            'multipliers' => [
                ['condition_type' => 'ev_ride', 'multiplier_value' => $value, 'is_stackable' => true],
            ],
        ], ['Authorization' => "Bearer {$this->adminToken}"])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('multipliers.0.multiplier_value');
    })->with([3.05, 10.0, 1.33, 0.95]);

    it('sets exclusive promotions per tier and keeps it when not sent', function () {
        $tiers = fn (bool $withExclusive) => collect([
            [1, 'Bronze', 0, 0, false, false, false],
            [2, 'Silver', 500, 2, false, false, true],
            [3, 'Gold', 2000, 5, false, true, false],
            [4, 'Platinum', 5000, 10, true, true, true],
            [5, 'Diamond', 15000, 15, true, true, true],
        ])->map(fn (array $t) => [
            'tier_level' => $t[0], 'tier_name' => $t[1], 'min_points_required' => $t[2],
            'booking_fee_discount_pct' => $t[3], 'ev_reservation_fee_waived' => $t[4], 'priority_matching_enabled' => $t[5],
            ...($withExclusive ? ['exclusive_promotions_enabled' => $t[6]] : []),
        ])->all();

        $this->putJson('/api/v1/admin/gamification/tiers', ['tiers' => $tiers(true)], ['Authorization' => "Bearer {$this->adminToken}"])
            ->assertOk()
            ->assertJsonPath('tiers.1.exclusive_promotions_enabled', true)
            ->assertJsonPath('tiers.2.exclusive_promotions_enabled', false);

        $this->putJson('/api/v1/admin/gamification/tiers', ['tiers' => $tiers(false)], ['Authorization' => "Bearer {$this->adminToken}"])
            ->assertOk()
            ->assertJsonPath('tiers.1.exclusive_promotions_enabled', true)
            ->assertJsonPath('tiers.2.exclusive_promotions_enabled', false);
    });

    it('rejects a tier discount with more than two decimals', function () {
        $tiers = TierConfig::allOrderedByLevel()->map(fn (TierConfig $t) => [
            'tier_level' => $t->tier_level, 'tier_name' => $t->tier_name, 'min_points_required' => $t->min_points_required,
            'booking_fee_discount_pct' => $t->tier_level === 2 ? 2.555 : (float) $t->booking_fee_discount_pct,
            'ev_reservation_fee_waived' => $t->ev_reservation_fee_waived, 'priority_matching_enabled' => $t->priority_matching_enabled,
        ])->all();

        $this->putJson('/api/v1/admin/gamification/tiers', ['tiers' => $tiers], ['Authorization' => "Bearer {$this->adminToken}"])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tiers.1.booking_fee_discount_pct');
    });

    it('shows that the shared journey multiplier is not awarded yet', function () {
        $multipliers = collect($this->getJson('/api/v1/admin/gamification/multipliers', [
            'Authorization' => "Bearer {$this->adminToken}",
        ])->assertOk()->json('multipliers'))->keyBy('condition_type');

        expect($multipliers['ev_ride']['is_awarded'])->toBeTrue()
            ->and($multipliers['off_peak']['is_awarded'])->toBeTrue()
            ->and($multipliers['shared_journey']['is_awarded'])->toBeFalse();
    });
});
