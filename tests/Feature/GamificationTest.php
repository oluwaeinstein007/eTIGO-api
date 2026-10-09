<?php

use App\Enums\AdminRole;
use App\Enums\MultiplierConditionType;
use App\Enums\TierLevel;
use App\Enums\UserType;
use App\Events\TierUpgraded;
use App\Jobs\CalculateCarbonScoreJob;
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
});
