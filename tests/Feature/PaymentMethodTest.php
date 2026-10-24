<?php

use App\Contracts\PaymentGateway;
use App\Models\AppSetting;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Services\FakePaymentGateway;

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
        'provider' => 'paystack',
        'is_default' => true,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/payment-methods');

    $response->assertOk()
        ->assertJsonCount(1, 'payment_methods')
        ->assertJsonPath('payment_methods.0.type', 'card')
        ->assertJsonPath('payment_methods.0.card_last_four', '4242')
        ->assertJsonPath('payment_methods.0.provider', 'paystack')
        ->assertJsonPath('payment_methods.0.is_default', true)
        ->assertJsonMissing(['gateway_token' => 'tok_test_123']);
});

it('lists cash only while cash payments are switched on', function () {
    $this->withToken($this->token)->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->assertJsonCount(0, 'payment_methods');

    AppSetting::setValue('wallet.cash_payments_enabled', 1, 'wallet');

    $this->withToken($this->token)->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->assertJsonCount(1, 'payment_methods')
        ->assertJsonPath('payment_methods.0.type', 'cash')
        ->assertJsonPath('payment_methods.0.label', 'Cash')
        ->assertJsonPath('payment_methods.0.is_default', true);
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
