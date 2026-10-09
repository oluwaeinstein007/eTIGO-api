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

    config(['wallet.flutterwave.webhook_hash' => 'test_webhook_hash']);
});

test('flutterwave wallet webhook verifies verif-hash', function () {
    $payload = [
        'event' => 'charge.completed',
        'data' => [
            'id' => 12345,
            'tx_ref' => 'TOPUP-ABC123',
            'amount' => 500,
            'status' => 'successful',
            'meta' => ['account_id' => 'acc-1'],
        ],
    ];

    $response = $this->postJson('/api/v1/webhooks/flutterwave-wallet', $payload, [
        'verif-hash' => 'test_webhook_hash',
    ]);

    $response->assertOk();
});

test('flutterwave wallet webhook rejects invalid verif-hash', function () {
    $payload = [
        'event' => 'charge.completed',
        'data' => [
            'id' => 12346,
            'tx_ref' => 'TOPUP-XYZ',
            'amount' => 500,
            'status' => 'successful',
        ],
    ];

    $response = $this->postJson('/api/v1/webhooks/flutterwave-wallet', $payload, [
        'verif-hash' => 'invalid-hash',
    ]);

    $response->assertForbidden();
});

test('charge.completed webhook dispatches ProcessTopupWebhookJob', function () {
    $ledgerService = app(LedgerService::class);
    $user = User::factory()->create();
    $account = $ledgerService->findOrCreateAccount(
        $user->getMorphClass(),
        $user->id,
        AccountType::PassengerWallet,
    );

    $payload = [
        'event' => 'charge.completed',
        'data' => [
            'id' => 99001,
            'tx_ref' => 'TOPUP-TESTREF123',
            'amount' => 5000,
            'status' => 'successful',
            'meta' => ['account_id' => $account->id, 'type' => 'wallet_topup'],
        ],
    ];

    $this->postJson('/api/v1/webhooks/flutterwave-wallet', $payload, [
        'verif-hash' => 'test_webhook_hash',
    ])->assertOk();

    Queue::assertPushed(ProcessTopupWebhookJob::class);
});

test('transfer.failed webhook dispatches ProcessPayoutWebhookJob', function () {
    $payload = [
        'event' => 'transfer.failed',
        'data' => [
            'id' => 99002,
            'reference' => 'PAYOUT-ABC123',
            'complete_message' => 'Account not found',
        ],
    ];

    $this->postJson('/api/v1/webhooks/flutterwave-wallet', $payload, [
        'verif-hash' => 'test_webhook_hash',
    ])->assertOk();

    Queue::assertPushed(ProcessPayoutWebhookJob::class);
});

test('duplicate webhook events are deduplicated', function () {
    $payload = [
        'event' => 'charge.completed',
        'data' => [
            'id' => 99003,
            'tx_ref' => 'TOPUP-DUPE',
            'amount' => 1000,
            'status' => 'successful',
            'meta' => ['account_id' => 'acc-1'],
        ],
    ];

    $this->postJson('/api/v1/webhooks/flutterwave-wallet', $payload, [
        'verif-hash' => 'test_webhook_hash',
    ])->assertOk();

    $this->postJson('/api/v1/webhooks/flutterwave-wallet', $payload, [
        'verif-hash' => 'test_webhook_hash',
    ])->assertOk();

    expect(WebhookEvent::where('event_id', '99003')->count())->toBe(1);
});
