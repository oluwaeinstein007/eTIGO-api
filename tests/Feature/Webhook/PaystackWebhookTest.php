<?php

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Jobs\ProcessPayoutWebhookJob;
use App\Jobs\ProcessTopupWebhookJob;
use App\Models\Account;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);

    config(['wallet.paystack.webhook_secret' => 'test_webhook_secret']);
});

test('paystack webhook verifies HMAC signature', function () {
    $payload = json_encode([
        'event' => 'charge.success',
        'data' => ['id' => 'evt_001', 'reference' => 'TOPUP-ABC123', 'amount' => 50000, 'metadata' => ['account_id' => 'acc-1']],
    ]);

    $signature = hash_hmac('sha512', $payload, 'test_webhook_secret');

    $response = $this->postJson('/api/v1/webhooks/paystack', json_decode($payload, true), [
        'x-paystack-signature' => $signature,
    ]);

    $response->assertOk();
});

test('paystack webhook rejects invalid signature', function () {
    $payload = json_encode([
        'event' => 'charge.success',
        'data' => ['id' => 'evt_002', 'reference' => 'TOPUP-XYZ', 'amount' => 50000],
    ]);

    $response = $this->postJson('/api/v1/webhooks/paystack', json_decode($payload, true), [
        'x-paystack-signature' => 'invalid-signature',
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
            'id' => 'evt_topup_100',
            'reference' => 'TOPUP-TESTREF123',
            'amount' => 500000,
            'metadata' => ['account_id' => $account->id, 'type' => 'wallet_topup'],
        ],
    ];

    $raw = json_encode($payload);
    $signature = hash_hmac('sha512', $raw, 'test_webhook_secret');

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => $signature,
    ])->assertOk();

    Queue::assertPushed(ProcessTopupWebhookJob::class);
});

test('transfer.failed webhook dispatches ProcessPayoutWebhookJob', function () {
    $payload = [
        'event' => 'transfer.failed',
        'data' => [
            'id' => 'evt_transfer_200',
            'transfer_code' => 'TRF_abc123',
            'reason' => 'Account not found',
        ],
    ];

    $raw = json_encode($payload);
    $signature = hash_hmac('sha512', $raw, 'test_webhook_secret');

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => $signature,
    ])->assertOk();

    Queue::assertPushed(ProcessPayoutWebhookJob::class);
});

test('duplicate webhook events are deduplicated', function () {
    $payload = [
        'event' => 'charge.success',
        'data' => [
            'id' => 'evt_dupe_300',
            'reference' => 'TOPUP-DUPE',
            'amount' => 100000,
            'metadata' => ['account_id' => 'acc-1'],
        ],
    ];

    $raw = json_encode($payload);
    $signature = hash_hmac('sha512', $raw, 'test_webhook_secret');

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => $signature,
    ])->assertOk();

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => $signature,
    ])->assertOk();

    expect(WebhookEvent::where('event_id', 'evt_dupe_300')->count())->toBe(1);
});
