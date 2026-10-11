<?php

use App\Enums\AccountType;
use App\Jobs\ProcessPayoutWebhookJob;
use App\Jobs\ProcessTopupWebhookJob;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);

    config(['wallet.paystack.secret_key' => 'test_secret_key']);
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

    $response = $this->postJson('/api/v1/webhooks/paystack-wallet', $payload, [
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

    $response = $this->postJson('/api/v1/webhooks/paystack-wallet', $payload, [
        'x-paystack-signature' => 'invalid-hash',
    ]);

    $response->assertForbidden();
});

test('charge.success webhook dispatches ProcessTopupWebhookJob', function () {
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

    $this->postJson('/api/v1/webhooks/paystack-wallet', $payload, [
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

    $this->postJson('/api/v1/webhooks/paystack-wallet', $payload, [
        'x-paystack-signature' => paystackSignature($payload),
    ])->assertOk();

    Queue::assertPushed(ProcessPayoutWebhookJob::class);
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

    $this->postJson('/api/v1/webhooks/paystack-wallet', $payload, [
        'x-paystack-signature' => $signature,
    ])->assertOk();

    $this->postJson('/api/v1/webhooks/paystack-wallet', $payload, [
        'x-paystack-signature' => $signature,
    ])->assertOk();

    expect(WebhookEvent::where('event_id', '99003')->count())->toBe(1);
});
