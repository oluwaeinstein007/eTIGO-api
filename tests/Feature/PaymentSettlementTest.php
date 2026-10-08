<?php

use App\Contracts\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\ProcessPaymentJob;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Services\FakePaymentGateway;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->app->instance(PaymentGateway::class, new FakePaymentGateway);

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;
});

describe('ProcessPaymentJob', function () {
    it('creates a pending_collection payment for cash rides', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
            'payment_method' => PaymentMethod::Cash,
            'final_fare_amount' => 3500,
        ]);

        $service = app(PaymentService::class);
        $payment = $service->processRidePayment($ride);

        expect($payment->method)->toBe(PaymentMethod::Cash)
            ->and($payment->status)->toBe(PaymentStatus::PendingCollection)
            ->and((float) $payment->amount)->toBe(3500.0);
    });

    it('captures card payment using default payment method', function () {
        UserPaymentMethod::create([
            'user_id' => $this->passenger->id,
            'gateway_token' => 'tok_test',
            'card_brand' => 'VISA',
            'card_last_four' => '4242',
            'is_default' => true,
        ]);

        $ride = Ride::factory()->completed()->withCard()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
            'final_fare_amount' => 5000,
        ]);

        $service = app(PaymentService::class);
        $payment = $service->processRidePayment($ride);

        expect($payment->method)->toBe(PaymentMethod::Card)
            ->and($payment->status)->toBe(PaymentStatus::Captured)
            ->and($payment->gateway_transaction_id)->not->toBeNull();
    });

    it('creates failed payment when no default card on file', function () {
        $ride = Ride::factory()->completed()->withCard()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
            'final_fare_amount' => 5000,
        ]);

        $service = app(PaymentService::class);
        $payment = $service->processRidePayment($ride);

        expect($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failure_reason)->toContain('No default payment method');
    });

    it('is idempotent - skips if payment already exists', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        Payment::factory()->captured()->create(['ride_id' => $ride->id]);

        $job = new ProcessPaymentJob($ride->id);
        $job->handle(app(PaymentService::class));

        expect(Payment::where('ride_id', $ride->id)->count())->toBe(1);
    });
});

describe('Cash confirmation', function () {
    it('allows driver to confirm cash collection', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
            'payment_method' => PaymentMethod::Cash,
            'final_fare_amount' => 3500,
        ]);

        Payment::factory()->cash()->create([
            'ride_id' => $ride->id,
            'amount' => 3500,
        ]);

        $response = $this->withToken($this->driverToken)
            ->postJson("/api/v1/rides/{$ride->id}/confirm-cash");

        $response->assertOk()
            ->assertJsonPath('payment.status', 'collected');

        $ride->refresh();
        expect($ride->payment_status)->toBe(PaymentStatus::Collected);
    });

    it('rejects cash confirmation for card rides', function () {
        $ride = Ride::factory()->completed()->withCard()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $response = $this->withToken($this->driverToken)
            ->postJson("/api/v1/rides/{$ride->id}/confirm-cash");

        $response->assertUnprocessable();
    });

    it('rejects cash confirmation by non-assigned driver', function () {
        $otherDriver = User::factory()->create(['type' => UserType::Driver]);
        $otherToken = $otherDriver->createToken('auth', ['driver'])->plainTextToken;

        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
            'payment_method' => PaymentMethod::Cash,
        ]);

        $response = $this->withToken($otherToken)
            ->postJson("/api/v1/rides/{$ride->id}/confirm-cash");

        $response->assertForbidden();
    });
});

describe('Tipping', function () {
    it('allows passenger to add a tip to a completed ride', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        Payment::factory()->cash()->collected()->create([
            'ride_id' => $ride->id,
            'amount' => 3500,
        ]);

        $response = $this->withToken($this->passengerToken)
            ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500]);

        $response->assertOk()
            ->assertJsonPath('payment.tip_amount', '500.00');
    });

    it('rejects tip on non-completed rides', function () {
        $ride = Ride::factory()->inProgress()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $response = $this->withToken($this->passengerToken)
            ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500]);

        $response->assertUnprocessable();
    });

    it('rejects duplicate tips', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        Payment::factory()->cash()->collected()->create([
            'ride_id' => $ride->id,
            'amount' => 3500,
            'tip_amount' => 200,
        ]);

        $response = $this->withToken($this->passengerToken)
            ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500]);

        $response->assertUnprocessable();
    });

    it('rejects tip below minimum', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        Payment::factory()->cash()->collected()->create([
            'ride_id' => $ride->id,
            'amount' => 3500,
        ]);

        $response = $this->withToken($this->passengerToken)
            ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 10]);

        $response->assertUnprocessable();
    });

    it('rejects tip from non-passenger of the ride', function () {
        $otherPassenger = User::factory()->create(['type' => UserType::Passenger]);
        $otherToken = $otherPassenger->createToken('auth', ['passenger'])->plainTextToken;

        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $response = $this->withToken($otherToken)
            ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500]);

        $response->assertForbidden();
    });
});

describe('Receipt', function () {
    it('returns receipt for completed ride to passenger', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
            'final_fare_amount' => 4500,
        ]);

        Payment::factory()->cash()->collected()->create([
            'ride_id' => $ride->id,
            'amount' => 4500,
        ]);

        $response = $this->withToken($this->passengerToken)
            ->getJson("/api/v1/rides/{$ride->id}/receipt");

        $response->assertOk()
            ->assertJsonStructure([
                'receipt' => [
                    'ride_id',
                    'date',
                    'pickup',
                    'destination',
                    'fare_breakdown',
                    'final_fare',
                    'currency',
                    'payment',
                ],
            ])
            ->assertJsonPath('receipt.final_fare', '4500.00')
            ->assertJsonPath('receipt.payment.method', 'cash');
    });

    it('returns receipt for completed ride to driver', function () {
        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $response = $this->withToken($this->driverToken)
            ->getJson("/api/v1/rides/{$ride->id}/receipt");

        $response->assertOk();
    });

    it('returns 404 for non-completed ride receipt', function () {
        $ride = Ride::factory()->inProgress()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $response = $this->withToken($this->passengerToken)
            ->getJson("/api/v1/rides/{$ride->id}/receipt");

        $response->assertNotFound();
    });

    it('returns 403 for unrelated user', function () {
        $otherUser = User::factory()->create(['type' => UserType::Passenger]);
        $otherToken = $otherUser->createToken('auth', ['passenger'])->plainTextToken;

        $ride = Ride::factory()->completed()->create([
            'passenger_id' => $this->passenger->id,
            'driver_id' => $this->driverUser->id,
        ]);

        $response = $this->withToken($otherToken)
            ->getJson("/api/v1/rides/{$ride->id}/receipt");

        $response->assertForbidden();
    });
});
