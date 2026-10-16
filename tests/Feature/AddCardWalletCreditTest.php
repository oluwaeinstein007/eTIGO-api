<?php

use App\Contracts\WalletGateway;
use App\Enums\AccountType;
use App\Enums\WalletTransactionStatus;
use App\Jobs\ProcessTopupWebhookJob;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Models\WalletTransaction;
use App\Services\LedgerService;
use Mockery\MockInterface;

beforeEach(function () {
    $this->passenger = User::factory()->passenger()->create();
});

function addCardWallet(User $user): Account
{
    return app(LedgerService::class)->findOrCreateAccount($user->getMorphClass(), $user->id, AccountType::PassengerWallet);
}

/**
 * A successful card payment as PaystackWalletGateway::verifyTransaction
 * reports it (amounts in kobo).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addCardCharge(string $reference, array $overrides = []): array
{
    return [
        'status' => 'successful',
        'amount' => 10000,
        'tx_ref' => $reference,
        'currency' => 'NGN',
        'channel' => 'card',
        'card_last_four' => '4081',
        'card_brand' => 'visa',
        'card_expiry_month' => 12,
        'card_expiry_year' => 2030,
        'authorization_code' => 'AUTH_abc123',
        'reusable' => true,
        ...$overrides,
    ];
}

function pendingAddCard(User $user, string $reference, bool $saveCard = true): WalletTransaction
{
    return WalletTransaction::create([
        'account_id' => addCardWallet($user)->id,
        'reference' => $reference,
        'amount' => 10000,
        'save_card' => $saveCard,
        'status' => WalletTransactionStatus::Pending,
    ]);
}

function fakeWalletGateway(): MockInterface
{
    $gateway = Mockery::mock(WalletGateway::class);
    app()->instance(WalletGateway::class, $gateway);

    return $gateway;
}

it('starts adding a card as a wallet top-up of the configured amount', function () {
    $gateway = fakeWalletGateway();
    $gateway->shouldReceive('initializePayment')->once()
        ->withArgs(fn (array $data) => str_starts_with($data['tx_ref'], 'TOPUP-')
            && $data['amount'] === 100
            && $data['channels'] === ['card']
            && $data['meta']['account_id'] === addCardWallet($this->passenger)->id
            && $data['meta']['type'] === 'card_verification')
        ->andReturn(['link' => 'https://checkout.paystack.test/abc']);

    $response = $this->actingAs($this->passenger)
        ->postJson('/api/v1/payments/initialize', ['redirect_url' => 'https://etigo.app/payment/callback'])
        ->assertOk()
        ->assertJsonPath('payment_link', 'https://checkout.paystack.test/abc')
        ->assertJsonPath('amount', 100)
        ->assertJsonPath('currency', 'NGN');

    $this->assertDatabaseHas('wallet_transactions', [
        'reference' => $response->json('tx_ref'),
        'account_id' => addCardWallet($this->passenger)->id,
        'amount' => 10000,
        'save_card' => true,
        'status' => 'pending',
    ]);
});

it('ignores the amount the app sends when adding a card', function () {
    $gateway = fakeWalletGateway();
    $gateway->shouldReceive('initializePayment')->once()
        ->withArgs(fn (array $data) => $data['amount'] === 100)
        ->andReturn(['link' => 'https://checkout.paystack.test/abc']);

    $this->actingAs($this->passenger)
        ->postJson('/api/v1/payments/initialize', [
            'amount' => 50000,
            'currency' => 'NGN',
            'redirect_url' => 'https://etigo.app/payment/callback',
        ])
        ->assertOk()
        ->assertJsonPath('amount', 100);
});

it('charges the card verification amount an admin set', function () {
    AppSetting::setValue('wallet.card_verification_amount', 20000, 'wallet');

    $gateway = fakeWalletGateway();
    $gateway->shouldReceive('initializePayment')->once()
        ->withArgs(fn (array $data) => $data['amount'] === 200)
        ->andReturn(['link' => 'https://checkout.paystack.test/abc']);

    $this->actingAs($this->passenger)
        ->postJson('/api/v1/payments/initialize', ['redirect_url' => 'https://etigo.app/payment/callback'])
        ->assertOk()
        ->assertJsonPath('amount', 200);
});

it('gives Paystack an email when a phone-only passenger adds a card', function () {
    $passenger = User::factory()->passenger()->create(['email' => null]);

    $gateway = fakeWalletGateway();
    $gateway->shouldReceive('initializePayment')->once()
        ->withArgs(fn (array $data) => $data['customer']['email'] === "{$passenger->id}@rider.etigo.ng")
        ->andReturn(['link' => 'https://checkout.paystack.test/abc']);

    $this->actingAs($passenger)
        ->postJson('/api/v1/payments/initialize', ['redirect_url' => 'https://etigo.app/payment/callback'])
        ->assertOk();
});

it('passes requested channels to Paystack when adding a card', function () {
    $gateway = fakeWalletGateway();
    $gateway->shouldReceive('initializePayment')->once()
        ->withArgs(fn (array $data) => $data['channels'] === ['card', 'bank_transfer'])
        ->andReturn(['link' => 'https://checkout.paystack.test/abc']);

    $this->actingAs($this->passenger)
        ->postJson('/api/v1/payments/initialize', [
            'redirect_url' => 'https://etigo.app/payment/callback',
            'channels' => ['card', 'bank_transfer'],
        ])
        ->assertOk();
});

it('validates the add-card request', function () {
    fakeWalletGateway()->shouldNotReceive('initializePayment');

    $this->actingAs($this->passenger)
        ->postJson('/api/v1/payments/initialize', ['channels' => ['crypto']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['redirect_url', 'channels.0'])
        ->assertJsonMissingValidationErrors(['amount', 'currency']);
});

it('credits the add-card payment to the wallet and saves the card', function () {
    pendingAddCard($this->passenger, 'TOPUP-CARD1');
    fakeWalletGateway()->shouldReceive('verifyTransaction')->with('TOPUP-CARD1')
        ->andReturn(addCardCharge('TOPUP-CARD1'));

    $response = $this->actingAs($this->passenger)
        ->getJson('/api/v1/payments/TOPUP-CARD1/verify')
        ->assertOk()
        ->assertJsonPath('status', 'successful')
        ->assertJsonPath('amount', 100)
        ->assertJsonPath('card_last_four', '4081')
        ->assertJsonPath('card_saved', true)
        ->assertJsonPath('wallet_credit', 100)
        ->assertJsonMissingPath('authorization_code');

    expect(addCardWallet($this->passenger)->fresh()->balance)->toBe(10000);
    expect(WalletTransaction::where('reference', 'TOPUP-CARD1')->first()->status)->toBe(WalletTransactionStatus::Completed);

    $card = UserPaymentMethod::where('user_id', $this->passenger->id)->sole();
    expect($response->json('payment_method_id'))->toBe($card->id)
        ->and($card->gateway_token)->toBe('AUTH_abc123')
        ->and($card->provider)->toBe('paystack')
        ->and($card->gateway_email)->toBe($this->passenger->paymentEmail())
        ->and($card->card_expiry_year)->toBe(2030)
        ->and($card->is_default)->toBeTrue();

    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->passenger->id,
        'type' => 'topup_success',
    ]);
});

it('credits an add-card payment only once when it is verified again', function () {
    pendingAddCard($this->passenger, 'TOPUP-CARD2');
    fakeWalletGateway()->shouldReceive('verifyTransaction')->with('TOPUP-CARD2')
        ->andReturn(addCardCharge('TOPUP-CARD2'));

    $this->actingAs($this->passenger)->getJson('/api/v1/payments/TOPUP-CARD2/verify')->assertOk();
    $this->actingAs($this->passenger)->getJson('/api/v1/payments/TOPUP-CARD2/verify')->assertOk();

    expect(addCardWallet($this->passenger)->fresh()->balance)->toBe(10000)
        ->and(UserPaymentMethod::where('user_id', $this->passenger->id)->count())->toBe(1);
});

it('does not let a passenger verify another passenger\'s add-card payment', function () {
    $other = User::factory()->passenger()->create();
    pendingAddCard($other, 'TOPUP-OTHER1');
    fakeWalletGateway()->shouldNotReceive('verifyTransaction');

    $this->actingAs($this->passenger)
        ->getJson('/api/v1/payments/TOPUP-OTHER1/verify')
        ->assertForbidden();

    expect(addCardWallet($other)->fresh()->balance)->toBe(0)
        ->and(UserPaymentMethod::count())->toBe(0);
});

it('credits the wallet but saves no card when the payment was not by a reusable card', function (array $overrides) {
    pendingAddCard($this->passenger, 'TOPUP-NOCARD');
    fakeWalletGateway()->shouldReceive('verifyTransaction')->with('TOPUP-NOCARD')
        ->andReturn(addCardCharge('TOPUP-NOCARD', $overrides));

    $this->actingAs($this->passenger)
        ->getJson('/api/v1/payments/TOPUP-NOCARD/verify')
        ->assertOk()
        ->assertJsonPath('card_saved', false)
        ->assertJsonPath('wallet_credit', 100);

    expect(addCardWallet($this->passenger)->fresh()->balance)->toBe(10000)
        ->and(UserPaymentMethod::count())->toBe(0);
})->with([
    'bank transfer' => [['channel' => 'bank_transfer', 'card_last_four' => null, 'authorization_code' => 'AUTH_bank', 'reusable' => false]],
    'card that cannot be charged again' => [['reusable' => false]],
]);

it('neither credits nor saves a card when the add-card payment failed', function () {
    pendingAddCard($this->passenger, 'TOPUP-FAILED');
    fakeWalletGateway()->shouldReceive('verifyTransaction')->with('TOPUP-FAILED')
        ->andReturn(addCardCharge('TOPUP-FAILED', ['status' => 'failed']));

    $this->actingAs($this->passenger)
        ->getJson('/api/v1/payments/TOPUP-FAILED/verify')
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('card_saved', false)
        ->assertJsonPath('wallet_credit', 0);

    expect(addCardWallet($this->passenger)->fresh()->balance)->toBe(0)
        ->and(UserPaymentMethod::count())->toBe(0);
});

it('saves the card when the webhook credits an add-card payment the app never verified', function () {
    $transaction = pendingAddCard($this->passenger, 'TOPUP-HOOK1');
    fakeWalletGateway()->shouldReceive('verifyTransaction')->once()->with('TOPUP-HOOK1')
        ->andReturn(addCardCharge('TOPUP-HOOK1'));

    ProcessTopupWebhookJob::dispatch($transaction->account_id, 10000, 'TOPUP-HOOK1');

    expect(addCardWallet($this->passenger)->fresh()->balance)->toBe(10000);
    $this->assertDatabaseHas('user_payment_methods', [
        'user_id' => $this->passenger->id,
        'card_last_four' => '4081',
        'gateway_token' => 'AUTH_abc123',
    ]);
});

it('refreshes the authorization of a card the passenger adds again', function () {
    $card = UserPaymentMethod::create([
        'user_id' => $this->passenger->id,
        'provider' => 'paystack',
        'gateway_token' => 'AUTH_old',
        'card_brand' => 'visa',
        'card_last_four' => '4081',
        'is_default' => true,
    ]);
    pendingAddCard($this->passenger, 'TOPUP-AGAIN');
    fakeWalletGateway()->shouldReceive('verifyTransaction')->with('TOPUP-AGAIN')
        ->andReturn(addCardCharge('TOPUP-AGAIN'));

    $this->actingAs($this->passenger)->getJson('/api/v1/payments/TOPUP-AGAIN/verify')->assertOk();

    expect(UserPaymentMethod::where('user_id', $this->passenger->id)->count())->toBe(1)
        ->and($card->fresh()->gateway_token)->toBe('AUTH_abc123');
});

it('remembers that a wallet top-up should save its card', function () {
    fakeWalletGateway()->shouldReceive('initializePayment')->once()
        ->andReturn(['link' => 'https://checkout.paystack.test/abc']);

    $response = $this->actingAs($this->passenger)
        ->postJson('/api/v1/passenger/wallet/topup', [
            'amount' => 100000,
            'callback_url' => 'https://app.etigo.test/wallet',
            'save_card' => true,
        ])
        ->assertOk();

    $this->assertDatabaseHas('wallet_transactions', [
        'reference' => $response->json('tx_ref'),
        'save_card' => true,
    ]);
});

it('saves the card of a top-up made with save_card', function () {
    pendingAddCard($this->passenger, 'TOPUP-SAVE1');
    fakeWalletGateway()->shouldReceive('verifyTransaction')->with('TOPUP-SAVE1')
        ->andReturn(addCardCharge('TOPUP-SAVE1'));

    $this->actingAs($this->passenger)
        ->getJson('/api/v1/passenger/wallet/topup/TOPUP-SAVE1/verify')
        ->assertOk();

    expect(UserPaymentMethod::where('user_id', $this->passenger->id)->count())->toBe(1);
});

it('does not save the card of a top-up made without save_card', function () {
    pendingAddCard($this->passenger, 'TOPUP-NOSAVE', saveCard: false);
    fakeWalletGateway()->shouldReceive('verifyTransaction')->once()->with('TOPUP-NOSAVE')
        ->andReturn(addCardCharge('TOPUP-NOSAVE'));

    $this->actingAs($this->passenger)
        ->getJson('/api/v1/passenger/wallet/topup/TOPUP-NOSAVE/verify')
        ->assertOk();

    expect(addCardWallet($this->passenger)->fresh()->balance)->toBe(10000)
        ->and(UserPaymentMethod::count())->toBe(0);
});
