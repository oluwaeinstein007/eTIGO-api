<?php

use App\Contracts\PaymentGateway;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Services\FakePaymentGateway;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('auth')->plainTextToken;

    $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
});

it('lists payment methods for the authenticated user', function () {
    UserPaymentMethod::create([
        'user_id' => $this->user->id,
        'gateway_token' => 'tok_test_123',
        'card_brand' => 'VISA',
        'card_last_four' => '4242',
        'card_expiry_month' => 12,
        'card_expiry_year' => 2028,
        'is_default' => true,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/payment-methods');

    $response->assertOk()
        ->assertJsonCount(2, 'payment_methods')
        ->assertJsonPath('payment_methods.0.type', 'cash')
        ->assertJsonPath('payment_methods.0.is_default', false)
        ->assertJsonPath('payment_methods.1.type', 'card')
        ->assertJsonPath('payment_methods.1.card_last_four', '4242')
        ->assertJsonPath('payment_methods.1.is_default', true)
        ->assertJsonMissing(['gateway_token' => 'tok_test_123']);
});

it('always includes cash as a payment method even with no saved cards', function () {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/payment-methods');

    $response->assertOk()
        ->assertJsonCount(1, 'payment_methods')
        ->assertJsonPath('payment_methods.0.type', 'cash')
        ->assertJsonPath('payment_methods.0.label', 'Cash')
        ->assertJsonPath('payment_methods.0.is_default', true);
});

it('initializes a payment and returns a payment link', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/payments/initialize', [
            'amount' => 5000,
            'currency' => 'NGN',
            'redirect_url' => 'https://etigo.app/payment/callback',
        ]);

    $response->assertOk()
        ->assertJsonStructure(['payment_link', 'tx_ref']);
});

it('validates required fields for payment initialization', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/payments/initialize', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['amount', 'currency', 'redirect_url']);
});

it('verifies a transaction and saves the payment method', function () {
    Cache::put('payment_tx_ref:FAKE-TX-VERIFY', $this->user->id, now()->addHours(24));

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/payments/fake_txn_123/verify');

    $response->assertOk()
        ->assertJsonPath('status', 'successful');

    $this->assertDatabaseHas('user_payment_methods', [
        'user_id' => $this->user->id,
        'card_last_four' => '4242',
        'card_brand' => 'VISA',
        'is_default' => true,
    ]);
});

it('sets a payment method as default', function () {
    $method1 = UserPaymentMethod::create([
        'user_id' => $this->user->id,
        'gateway_token' => 'tok_1',
        'card_brand' => 'VISA',
        'card_last_four' => '4242',
        'is_default' => true,
    ]);

    $method2 = UserPaymentMethod::create([
        'user_id' => $this->user->id,
        'gateway_token' => 'tok_2',
        'card_brand' => 'MASTERCARD',
        'card_last_four' => '5555',
        'is_default' => false,
    ]);

    $response = $this->withToken($this->token)
        ->patchJson("/api/v1/payment-methods/{$method2->id}/default");

    $response->assertOk();

    expect($method1->fresh()->is_default)->toBeFalse();
    expect($method2->fresh()->is_default)->toBeTrue();
});

it('deletes a payment method', function () {
    $method = UserPaymentMethod::create([
        'user_id' => $this->user->id,
        'gateway_token' => 'tok_del',
        'card_brand' => 'VISA',
        'card_last_four' => '1234',
        'is_default' => false,
    ]);

    $response = $this->withToken($this->token)
        ->deleteJson("/api/v1/payment-methods/{$method->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('user_payment_methods', ['id' => $method->id]);
});

it('returns 403 when setting another user payment method as default', function () {
    $otherUser = User::factory()->create();

    $method = UserPaymentMethod::create([
        'user_id' => $otherUser->id,
        'gateway_token' => 'tok_other',
        'card_brand' => 'VISA',
        'card_last_four' => '9999',
        'is_default' => false,
    ]);

    $response = $this->withToken($this->token)
        ->patchJson("/api/v1/payment-methods/{$method->id}/default");

    $response->assertForbidden();
});

it('returns 401 when unauthenticated', function () {
    $response = $this->getJson('/api/v1/payment-methods');

    $response->assertUnauthorized();
});
