<?php

use App\Contracts\WalletGateway;
use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Jobs\ProcessPayoutTransferJob;
use App\Models\Payout;
use App\Services\LedgerService;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

function payoutBeingSent(array $attributes = []): Payout
{
    $payout = Payout::factory()->approved()->create(['amount' => 250000, ...$attributes]);
    $payout->update([
        'gateway_reference' => "PAYOUT-{$payout->id}",
        'gateway_recipient_code' => 'RCP_driver',
        ...$attributes,
    ]);

    return $payout->fresh();
}

/** The payload a transfer for this payout is started with. */
function approvalRequest(Payout $payout, array $overrides = []): array
{
    return [
        'source' => 'balance',
        'reason' => "E-tiGo driver payout #{$payout->id}",
        'amount' => $payout->amount,
        'recipient' => $payout->gateway_recipient_code,
        'reference' => $payout->gateway_reference,
        'currency' => 'NGN',
        ...$overrides,
    ];
}

it('approves a transfer that matches a payout being sent', function () {
    $payout = payoutBeingSent();

    $this->postJson('/api/v1/webhooks/paystack/transfer-approval', approvalRequest($payout))
        ->assertOk()
        ->assertJson(['message' => 'Transfer approved.']);
});

it('approves a payout already marked processing and a payload wrapped in data', function () {
    $payout = payoutBeingSent(['status' => PayoutStatus::Processing]);

    $this->postJson('/api/v1/webhooks/paystack/transfer-approval', ['data' => approvalRequest($payout)])->assertOk();
});

it('accepts the recipient as an object carrying its code', function () {
    $payout = payoutBeingSent();

    $this->postJson('/api/v1/webhooks/paystack/transfer-approval', approvalRequest($payout, [
        'recipient' => ['recipient_code' => 'RCP_driver', 'details' => ['account_number' => '0123456789']],
    ]))->assertOk();
});

it('rejects a transfer that does not match a payout being sent', function (Closure $request) {
    $payout = payoutBeingSent();

    $this->postJson('/api/v1/webhooks/paystack/transfer-approval', $request($payout))
        ->assertStatus(400)
        ->assertJson(['message' => 'Transfer rejected.']);
})->with([
    'unknown reference' => fn (Payout $p) => approvalRequest($p, ['reference' => 'ATTACKER-1']),
    'no reference' => fn (Payout $p) => approvalRequest($p, ['reference' => null]),
    'different amount' => fn (Payout $p) => approvalRequest($p, ['amount' => 2500000]),
    'amount missing' => fn (Payout $p) => approvalRequest($p, ['amount' => null]),
    'another recipient' => fn (Payout $p) => approvalRequest($p, ['recipient' => 'RCP_attacker']),
    'recipient missing' => fn (Payout $p) => approvalRequest($p, ['recipient' => null]),
    'another currency' => fn (Payout $p) => approvalRequest($p, ['currency' => 'USD']),
    'currency not a string' => fn (Payout $p) => approvalRequest($p, ['currency' => ['NGN']]),
    'reference not a string' => fn (Payout $p) => approvalRequest($p, ['reference' => [$p->gateway_reference]]),
    'empty body' => fn () => [],
]);

it('rejects a transfer for a payout that is not being sent', function (PayoutStatus $status) {
    $payout = payoutBeingSent(['status' => $status]);

    $this->postJson('/api/v1/webhooks/paystack/transfer-approval', approvalRequest($payout))->assertStatus(400);
})->with([PayoutStatus::Requested, PayoutStatus::Paid, PayoutStatus::Failed, PayoutStatus::Rejected, PayoutStatus::Reversed]);

it('rejects a transfer when the payout has no recipient code saved', function () {
    $payout = payoutBeingSent(['gateway_recipient_code' => null]);

    $this->postJson('/api/v1/webhooks/paystack/transfer-approval', approvalRequest($payout, ['recipient' => 'RCP_driver']))->assertStatus(400);
});

it('logs a critical alert naming why a transfer was rejected', function () {
    $critical = [];
    Log::listen(function (MessageLogged $log) use (&$critical) {
        if ($log->level === 'critical') {
            $critical[] = $log;
        }
    });
    $payout = payoutBeingSent();

    $this->postJson('/api/v1/webhooks/paystack/transfer-approval', approvalRequest($payout, ['recipient' => 'RCP_attacker']))->assertStatus(400);

    expect($critical)->toHaveCount(1)
        ->and($critical[0]->message)->toContain('the recipient is not the payout\'s bank account')
        ->and($critical[0]->context)->toMatchArray([
            'reference' => "PAYOUT-{$payout->id}",
            'recipient' => 'RCP_attacker',
        ]);
});

it('approves the transfer the payout job starts, while the transfer request is in flight', function () {
    $this->seed(SystemAccountSeeder::class);
    $payout = Payout::factory()->approved()->create(['amount' => 250000]);
    $ledger = app(LedgerService::class);
    $ledger->postJournal([
        ['account_id' => $ledger->systemAccount(AccountType::PspClearing)->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 500000],
        ['account_id' => $ledger->findOrCreateAccount('App\\Models\\Driver', $payout->driver_id, AccountType::DriverEarningsAvailable)->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 500000],
    ], ['description' => 'Test earnings']);

    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('createTransferRecipient')->once()
        ->with($payout->bankAccount->account_number, $payout->bankAccount->bank_code)
        ->andReturn('RCP_driver');
    // Paystack asks the approval URL before it answers the transfer request.
    $gateway->shouldReceive('initiateTransfer')->once()->andReturnUsing(function (array $data) {
        $this->postJson('/api/v1/webhooks/paystack/transfer-approval', [
            'source' => 'balance',
            'amount' => (int) round($data['amount'] * 100),
            'recipient' => $data['recipient_code'],
            'reference' => $data['reference'],
        ])->assertOk();

        return ['id' => 'TRF-1', 'reference' => $data['reference'], 'status' => 'pending'];
    });
    $this->app->instance(WalletGateway::class, $gateway);

    app()->call([new ProcessPayoutTransferJob($payout->id), 'handle']);

    expect($payout->fresh())
        ->status->toBe(PayoutStatus::Processing)
        ->gateway_recipient_code->toBe('RCP_driver');
});

it('pauses critical alerts from an address that keeps sending rejected transfers', function () {
    $levels = [];
    Log::listen(function (MessageLogged $log) use (&$levels) {
        $levels[] = $log->level;
    });

    foreach (range(1, 7) as $attempt) {
        $this->postJson('/api/v1/webhooks/paystack/transfer-approval', ['reference' => "JUNK-{$attempt}"])->assertStatus(400);
    }

    expect(array_count_values($levels))->toMatchArray(['critical' => 5, 'warning' => 2]);
});

it('finds the transfer in an event wrapper whatever its depth, including a list', function (Closure $wrap) {
    $payout = payoutBeingSent();

    $this->postJson('/api/v1/webhooks/paystack/transfer-approval', $wrap(approvalRequest($payout)))->assertOk();
})->with([
    'list under data' => fn (array $t) => ['event' => 'transferrequest.approval-required', 'data' => [$t]],
    'object under data' => fn (array $t) => ['event' => 'transferrequest.approval-required', 'data' => ['transfer' => $t]],
    'top-level list' => fn (array $t) => [$t],
]);

it('rejects a bulk request when any transfer in it is not ours', function () {
    $payout = payoutBeingSent();

    $this->postJson('/api/v1/webhooks/paystack/transfer-approval', ['data' => [
        approvalRequest($payout),
        approvalRequest($payout, ['reference' => 'ATTACKER-1', 'recipient' => 'RCP_attacker']),
    ]])->assertStatus(400);
});

it('reads a JSON body sent without a JSON content type', function () {
    $payout = payoutBeingSent();

    $this->call('POST', '/api/v1/webhooks/paystack/transfer-approval', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(approvalRequest($payout)))
        ->assertOk();
});

it('logs where things are in a rejected request but not the bank details', function () {
    $critical = [];
    Log::listen(function (MessageLogged $log) use (&$critical) {
        if ($log->level === 'critical') {
            $critical[] = $log;
        }
    });

    $this->postJson('/api/v1/webhooks/paystack/transfer-approval', [
        'event' => 'transferrequest.approval-required',
        'data' => ['transfer' => ['ref' => 'X', 'recipient' => ['details' => ['account_number' => '0123456789', 'account_name' => 'Ada Driver']]]],
    ])->assertStatus(400);

    expect($critical)->toHaveCount(1)
        ->and($critical[0]->message)->toContain('no transfer found in the request')
        ->and($critical[0]->context['event'])->toBe('transferrequest.approval-required')
        ->and($critical[0]->context['payload_shape'])->toBe([
            'event' => 'string',
            'data' => ['transfer' => ['ref' => 'string', 'recipient' => ['details' => 'array']]],
        ])
        ->and(json_encode($critical[0]->context))->not->toContain('0123456789')->not->toContain('Ada Driver');
});
