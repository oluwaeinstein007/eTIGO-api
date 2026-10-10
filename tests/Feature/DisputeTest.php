<?php

use App\Contracts\PaymentGateway;
use App\Enums\AdminRole;
use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserType;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\User;
use App\Services\FakePaymentGateway;

beforeEach(function () {
    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->admin = User::factory()->create([
        'type' => UserType::Admin,
        'admin_role' => AdminRole::SuperAdmin,
    ]);
    $this->adminToken = $this->admin->createToken('auth', ['admin', 'super_admin'])->plainTextToken;

    $this->completedRide = Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
    ]);
});

describe('POST /rides/{ride}/dispute', function () {
    it('allows passenger to file a dispute', function () {
        $response = $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/dispute",
            [
                'category' => DisputeCategory::FareDispute->value,
                'description' => 'The fare charged was much higher than the estimate.',
            ],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(201)
            ->assertJsonPath('dispute.category', 'fare_dispute')
            ->assertJsonPath('dispute.status', 'open')
            ->assertJsonPath('message', 'Dispute filed successfully.');

        $this->assertDatabaseHas('disputes', [
            'ride_id' => $this->completedRide->id,
            'reported_by_user_id' => $this->passenger->id,
            'category' => 'fare_dispute',
        ]);
    });

    it('allows driver to file a dispute', function () {
        $response = $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/dispute",
            [
                'category' => DisputeCategory::Other->value,
                'description' => 'Passenger was rude and damaged the vehicle interior.',
            ],
            ['Authorization' => "Bearer {$this->driverToken}"],
        );

        $response->assertStatus(201);
    });

    it('rejects dispute for non-completed ride', function () {
        $ride = Ride::factory()->inProgress()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $response = $this->postJson(
            "/api/v1/rides/{$ride->id}/dispute",
            [
                'category' => DisputeCategory::FareDispute->value,
                'description' => 'This should fail validation.',
            ],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('ride');
    });

    it('prevents duplicate dispute from same user', function () {
        Dispute::create([
            'ride_id' => $this->completedRide->id,
            'reported_by_user_id' => $this->passenger->id,
            'category' => DisputeCategory::FareDispute,
            'description' => 'First dispute.',
            'status' => DisputeStatus::Open,
        ]);

        $response = $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/dispute",
            [
                'category' => DisputeCategory::SafetyConcern->value,
                'description' => 'Second dispute should be rejected.',
            ],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('ride');
    });

    it('validates category is valid enum', function () {
        $response = $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/dispute",
            [
                'category' => 'invalid_category',
                'description' => 'Testing invalid category.',
            ],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('category');
    });

    it('validates description minimum length', function () {
        $response = $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/dispute",
            [
                'category' => DisputeCategory::FareDispute->value,
                'description' => 'Short',
            ],
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('description');
    });

    it('requires authentication', function () {
        $this->postJson(
            "/api/v1/rides/{$this->completedRide->id}/dispute",
            [
                'category' => DisputeCategory::FareDispute->value,
                'description' => 'Unauthenticated dispute attempt.',
            ],
        )->assertStatus(401);
    });
});

describe('Admin Disputes API', function () {
    it('lists disputes with pagination', function () {
        Dispute::factory()->count(3)->create();

        $response = $this->getJson(
            '/api/v1/admin/disputes',
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonCount(3, 'disputes')
            ->assertJsonStructure(['disputes', 'meta']);
    });

    it('filters disputes by status', function () {
        Dispute::factory()->create(['status' => DisputeStatus::Open]);
        Dispute::factory()->resolved()->create();

        $response = $this->getJson(
            '/api/v1/admin/disputes?status=open',
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonCount(1, 'disputes');
    });

    it('filters disputes by category', function () {
        Dispute::factory()->create(['category' => DisputeCategory::FareDispute]);
        Dispute::factory()->create(['category' => DisputeCategory::SafetyConcern]);

        $response = $this->getJson(
            '/api/v1/admin/disputes?category=fare_dispute',
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonCount(1, 'disputes');
    });

    it('filters disputes by date range', function () {
        Dispute::factory()->create(['created_at' => now()->subDays(10)]);
        Dispute::factory()->create(['created_at' => now()->subDay()]);

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();

        $response = $this->getJson(
            "/api/v1/admin/disputes?from={$from}&to={$to}",
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonCount(1, 'disputes');
    });

    it('searches disputes by description or reporter name', function () {
        $reporter = User::factory()->create([
            'first_name' => 'Unique',
            'last_name' => 'Reporter',
            'type' => UserType::Passenger,
        ]);

        Dispute::factory()->create([
            'reported_by_user_id' => $reporter->id,
            'description' => 'Some generic issue.',
        ]);
        Dispute::factory()->create([
            'description' => 'Another unrelated dispute.',
        ]);

        $response = $this->getJson(
            '/api/v1/admin/disputes?search=Unique',
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonCount(1, 'disputes');
    });

    it('shows dispute detail with ride information', function () {
        $dispute = Dispute::factory()->create([
            'ride_id' => $this->completedRide->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->getJson(
            "/api/v1/admin/disputes/{$dispute->id}",
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('dispute.id', $dispute->id)
            ->assertJsonStructure([
                'dispute' => ['id', 'ride_id', 'category', 'status', 'ride'],
            ]);
    });

    it('resolves a dispute', function () {
        $dispute = Dispute::factory()->create([
            'ride_id' => $this->completedRide->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/disputes/{$dispute->id}/resolve",
            [
                'status' => DisputeStatus::Resolved->value,
                'resolution_notes' => 'Fare adjusted. Partial refund issued to passenger.',
            ],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('dispute.status', 'resolved')
            ->assertJsonPath('message', 'Dispute resolved successfully.');

        $this->assertDatabaseHas('disputes', [
            'id' => $dispute->id,
            'status' => 'resolved',
            'resolved_by_admin_id' => $this->admin->id,
        ]);
    });

    it('dismisses a dispute', function () {
        $dispute = Dispute::factory()->create([
            'ride_id' => $this->completedRide->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/disputes/{$dispute->id}/resolve",
            [
                'status' => DisputeStatus::Dismissed->value,
                'resolution_notes' => 'Investigation found no merit to the claim.',
            ],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('dispute.status', 'dismissed');
    });

    it('prevents resolving already resolved dispute', function () {
        $dispute = Dispute::factory()->resolved()->create([
            'ride_id' => $this->completedRide->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/disputes/{$dispute->id}/resolve",
            [
                'status' => DisputeStatus::Resolved->value,
                'resolution_notes' => 'Trying to resolve again.',
            ],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('status');
    });

    it('requires resolution notes', function () {
        $dispute = Dispute::factory()->create([
            'ride_id' => $this->completedRide->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/disputes/{$dispute->id}/resolve",
            [
                'status' => DisputeStatus::Resolved->value,
            ],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('resolution_notes');
    });

    it('resolves a dispute with a partial card refund', function () {
        app()->bind(PaymentGateway::class, fn () => new FakePaymentGateway);

        $ride = Ride::factory()->completed()->withCard()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
            'final_fare_amount' => 5000.00,
        ]);

        $payment = Payment::create([
            'ride_id' => $ride->id,
            'amount' => 5000.00,
            'currency' => 'NGN',
            'method' => PaymentMethod::Card->value,
            'gateway_transaction_id' => 'fake_txn_test123',
            'tip_amount' => 0,
            'status' => PaymentStatus::Captured,
        ]);

        $dispute = Dispute::factory()->create([
            'ride_id' => $ride->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/disputes/{$dispute->id}/resolve",
            [
                'status' => DisputeStatus::Resolved->value,
                'resolution_notes' => 'Fare overcharge confirmed. Partial refund issued.',
                'refund_amount' => 1500.00,
            ],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('dispute.status', 'resolved')
            ->assertJsonPath('dispute.refund_amount', '1500.00')
            ->assertJsonPath('dispute.refund_currency', 'NGN');

        expect($response->json('message'))->toContain('Refund');

        $this->assertDatabaseHas('disputes', [
            'id' => $dispute->id,
            'refund_amount' => 1500.00,
            'refund_currency' => 'NGN',
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Refunded->value,
        ]);
    });

    it('resolves a dispute with a full refund', function () {
        app()->bind(PaymentGateway::class, fn () => new FakePaymentGateway);

        $ride = Ride::factory()->completed()->withCard()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
            'final_fare_amount' => 3000.00,
        ]);

        Payment::create([
            'ride_id' => $ride->id,
            'amount' => 3000.00,
            'currency' => 'NGN',
            'method' => PaymentMethod::Card->value,
            'gateway_transaction_id' => 'fake_txn_full_refund',
            'tip_amount' => 0,
            'status' => PaymentStatus::Captured,
        ]);

        $dispute = Dispute::factory()->create([
            'ride_id' => $ride->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/disputes/{$dispute->id}/resolve",
            [
                'status' => DisputeStatus::Resolved->value,
                'resolution_notes' => 'Driver no-show confirmed. Full refund issued.',
                'refund_amount' => 3000.00,
            ],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('dispute.refund_amount', '3000.00');
    });

    it('rejects refund exceeding payment amount', function () {
        $ride = Ride::factory()->completed()->withCard()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
            'final_fare_amount' => 2000.00,
        ]);

        Payment::create([
            'ride_id' => $ride->id,
            'amount' => 2000.00,
            'currency' => 'NGN',
            'method' => PaymentMethod::Card->value,
            'gateway_transaction_id' => 'fake_txn_over',
            'tip_amount' => 0,
            'status' => PaymentStatus::Captured,
        ]);

        $dispute = Dispute::factory()->create([
            'ride_id' => $ride->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/disputes/{$dispute->id}/resolve",
            [
                'status' => DisputeStatus::Resolved->value,
                'resolution_notes' => 'Attempting over-refund.',
                'refund_amount' => 5000.00,
            ],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('refund_amount');
    });

    it('rejects refund for cash payments', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
            'final_fare_amount' => 2000.00,
        ]);

        Payment::create([
            'ride_id' => $ride->id,
            'amount' => 2000.00,
            'currency' => 'NGN',
            'method' => PaymentMethod::Cash->value,
            'tip_amount' => 0,
            'status' => PaymentStatus::Collected,
        ]);

        $dispute = Dispute::factory()->create([
            'ride_id' => $ride->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/disputes/{$dispute->id}/resolve",
            [
                'status' => DisputeStatus::Resolved->value,
                'resolution_notes' => 'Cash ride cannot be refunded via gateway.',
                'refund_amount' => 500.00,
            ],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('refund_amount');
    });

    it('rejects refund on dismissed disputes', function () {
        $dispute = Dispute::factory()->create([
            'ride_id' => $this->completedRide->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/disputes/{$dispute->id}/resolve",
            [
                'status' => DisputeStatus::Dismissed->value,
                'resolution_notes' => 'No merit found. Dismissing.',
                'refund_amount' => 500.00,
            ],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('refund_amount');
    });

    it('resolves without refund when refund_amount is omitted', function () {
        $dispute = Dispute::factory()->create([
            'ride_id' => $this->completedRide->id,
            'reported_by_user_id' => $this->passenger->id,
        ]);

        $response = $this->postJson(
            "/api/v1/admin/disputes/{$dispute->id}/resolve",
            [
                'status' => DisputeStatus::Resolved->value,
                'resolution_notes' => 'Resolved without financial adjustment.',
            ],
            ['Authorization' => "Bearer {$this->adminToken}"],
        );

        $response->assertOk()
            ->assertJsonPath('dispute.status', 'resolved')
            ->assertJsonMissing(['refund_amount']);
    });

    it('rejects non-admin access', function () {
        $response = $this->getJson(
            '/api/v1/admin/disputes',
            ['Authorization' => "Bearer {$this->passengerToken}"],
        );

        $response->assertStatus(403);
    });
});
