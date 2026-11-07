<?php

use App\Contracts\PaymentGateway;
use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserType;
use App\Jobs\ProcessPayoutWebhookJob;
use App\Jobs\ProcessTopupWebhookJob;
use App\Models\Driver;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\Ride;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\PaymentReceiptNotification;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);

    config(['services.paystack.secret_key' => 'test_secret_key']);
});

function paystackSignature(array $payload): string
{
    return hash_hmac('sha512', json_encode($payload), 'test_secret_key');
}

test('paystack webhook verifies HMAC signature', function () {
    $payload = [
        'event' => 'charge.success',
        'data' => [
            'id' => 12345,
            'reference' => 'TOPUP-ABC123',
            'amount' => 50000,
            'status' => 'success',
            'metadata' => ['account_id' => 'acc-1'],
        ],
    ];

    $response = $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => paystackSignature($payload),
    ]);

    $response->assertOk();
});

test('paystack webhook rejects invalid signature', function () {
    $payload = [
        'event' => 'charge.success',
        'data' => [
            'id' => 12346,
            'reference' => 'TOPUP-XYZ',
            'amount' => 50000,
            'status' => 'success',
        ],
    ];

    $response = $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => 'invalid-hash',
    ]);

    $response->assertForbidden();
});

test('charge.success webhook dispatches ProcessTopupWebhookJob for wallet topups', function () {
    $ledgerService = app(LedgerService::class);
    $user = User::factory()->create();
    $account = $ledgerService->findOrCreateAccount(
        $user->getMorphClass(),
        $user->id,
        AccountType::PassengerWallet,
    );

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'id' => 99001,
            'reference' => 'TOPUP-TESTREF123',
            'amount' => 500000,
            'status' => 'success',
            'metadata' => ['account_id' => $account->id, 'type' => 'wallet_topup'],
        ],
    ];

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => paystackSignature($payload),
    ])->assertOk();

    Queue::assertPushed(ProcessTopupWebhookJob::class);
});

test('transfer.failed webhook dispatches ProcessPayoutWebhookJob', function () {
    $payload = [
        'event' => 'transfer.failed',
        'data' => [
            'id' => 99002,
            'reference' => 'PAYOUT-ABC123',
            'reason' => 'Account not found',
        ],
    ];

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => paystackSignature($payload),
    ])->assertOk();

    Queue::assertPushed(ProcessPayoutWebhookJob::class);
});

test('transfer.rejected webhook fails the payout', function () {
    $payload = [
        'event' => 'transfer.rejected',
        'data' => [
            'id' => 99004,
            'reference' => 'PAYOUT-REJECTED',
            'status' => 'rejected',
        ],
    ];

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => paystackSignature($payload),
    ])->assertOk();

    Queue::assertPushed(ProcessPayoutWebhookJob::class, fn (ProcessPayoutWebhookJob $job) => $job->transferCode === '99004'
        && $job->status === 'failed');
});

test('duplicate webhook events are deduplicated', function () {
    $payload = [
        'event' => 'charge.success',
        'data' => [
            'id' => 99003,
            'reference' => 'TOPUP-DUPE',
            'amount' => 100000,
            'status' => 'success',
            'metadata' => ['account_id' => 'acc-1'],
        ],
    ];

    $signature = paystackSignature($payload);

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => $signature,
    ])->assertOk();

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => $signature,
    ])->assertOk();

    expect(WebhookEvent::where('event_id', '99003')->count())->toBe(1);
});

test('a payment that recovers from Failed to Captured still settles driver earnings and sends a receipt', function () {
    Notification::fake();

    $passenger = User::factory()->create(['type' => UserType::Passenger, 'email' => 'passenger@example.com']);
    $driverUser = User::factory()->create(['type' => UserType::Driver]);
    Driver::factory()->create(['user_id' => $driverUser->id]);

    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $passenger->id,
        'driver_id' => $driverUser->id,
        'payment_method' => PaymentMethod::Card,
        'final_fare_amount' => 4000,
        'fare_currency' => 'NGN',
    ]);

    // An earlier webhook delivery's verification call failed transiently,
    // leaving the payment Failed even though the charge actually succeeded.
    $payment = Payment::factory()->create([
        'ride_id' => $ride->id,
        'amount' => 4000,
        'currency' => 'NGN',
        'method' => PaymentMethod::Card,
        'status' => PaymentStatus::Failed,
        'failure_reason' => 'Gateway status: abandoned',
        'gateway_transaction_id' => 'TXN-RECOVER-1',
    ]);

    $mockGateway = Mockery::mock(PaymentGateway::class);
    $mockGateway->shouldReceive('verifyTransaction')
        ->once()
        ->andReturn([
            'status' => 'successful',
            'tx_ref' => 'FAKE-TX-REF',
            'transaction_id' => 'TXN-RECOVER-1',
            'amount' => 4000.0,
            'currency' => 'NGN',
            'payment_type' => 'card',
            'card_last_four' => '4242',
            'card_brand' => 'VISA',
            'authorization_code' => 'AUTH_fake',
        ]);
    $this->app->instance(PaymentGateway::class, $mockGateway);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'id' => 'TXN-RECOVER-1',
            'reference' => 'FAKE-TX-REF',
            'amount' => 400000,
            'status' => 'success',
        ],
    ];

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => paystackSignature($payload),
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Captured);
    expect(Journal::where('idempotency_key', "card-settlement-{$ride->id}")->exists())->toBeTrue();

    Notification::assertSentTo($passenger, PaymentReceiptNotification::class, function (PaymentReceiptNotification $notification) use ($payment) {
        return $notification->paymentReference === (string) $payment->id
            && $notification->paymentMethod === 'card';
    });
});
