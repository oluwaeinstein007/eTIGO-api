<?php

use App\Contracts\PaymentGateway;
use App\Services\FallbackPaymentGateway;
use App\Services\FlutterwavePaymentGateway;
use App\Services\PaystackPaymentGateway;
use Tests\TestCase;

uses(TestCase::class);

describe('FallbackPaymentGateway token charging', function () {
    it('routes Flutterwave tokens (FLW_) directly to Flutterwave gateway when fallback', function () {
        $primary = Mockery::mock(PaystackPaymentGateway::class);
        $fallback = Mockery::mock(FlutterwavePaymentGateway::class);

        $payload = [
            'token' => 'FLW_XTKcb1W1jFPqGPyu9Pi2K9Xb',
            'email' => 'passenger@example.com',
            'amount' => 500.0,
            'currency' => 'NGN',
            'tx_ref' => 'ETIGO-TIP-TEST123',
        ];

        // Primary (Paystack) must NOT be invoked because it will fail with 400 pattern mismatch
        $primary->shouldNotReceive('chargeWithToken');

        $fallback->shouldReceive('chargeWithToken')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 'successful',
                'transaction_id' => 'flw_tx_123',
                'amount' => 500.0,
            ]);

        $gateway = new FallbackPaymentGateway($primary, $fallback);
        $result = $gateway->chargeWithToken($payload);

        expect($result['status'])->toBe('successful')
            ->and($result['transaction_id'])->toBe('flw_tx_123')
            ->and($result['amount'])->toBe(500.0);
    });

    it('routes Paystack authorization tokens (AUTH_) directly to Paystack gateway when primary', function () {
        $primary = Mockery::mock(PaystackPaymentGateway::class);
        $fallback = Mockery::mock(FlutterwavePaymentGateway::class);

        $payload = [
            'token' => 'AUTH_w123456789',
            'email' => 'passenger@example.com',
            'amount' => 1000.0,
            'currency' => 'NGN',
            'tx_ref' => 'ETIGO-RIDE-TEST456',
        ];

        $primary->shouldReceive('chargeWithToken')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 'successful',
                'transaction_id' => 'pstk_tx_456',
                'amount' => 1000.0,
            ]);

        $fallback->shouldNotReceive('chargeWithToken');

        $gateway = new FallbackPaymentGateway($primary, $fallback);
        $result = $gateway->chargeWithToken($payload);

        expect($result['status'])->toBe('successful')
            ->and($result['transaction_id'])->toBe('pstk_tx_456')
            ->and($result['amount'])->toBe(1000.0);
    });

    it('routes Flutterwave tokens to primary when primary is Flutterwave', function () {
        $primary = Mockery::mock(FlutterwavePaymentGateway::class);
        $fallback = Mockery::mock(PaystackPaymentGateway::class);

        $payload = [
            'token' => 'FLW_pm_custom_token',
            'email' => 'passenger@example.com',
            'amount' => 500.0,
            'currency' => 'NGN',
            'tx_ref' => 'ETIGO-TIP-789',
        ];

        $primary->shouldReceive('chargeWithToken')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 'successful',
                'transaction_id' => 'flw_tx_789',
                'amount' => 500.0,
            ]);

        $fallback->shouldNotReceive('chargeWithToken');

        $gateway = new FallbackPaymentGateway($primary, $fallback);
        $result = $gateway->chargeWithToken($payload);

        expect($result['status'])->toBe('successful')
            ->and($result['transaction_id'])->toBe('flw_tx_789');
    });

    it('routes Paystack tokens to fallback when fallback is Paystack', function () {
        $primary = Mockery::mock(FlutterwavePaymentGateway::class);
        $fallback = Mockery::mock(PaystackPaymentGateway::class);

        $payload = [
            'token' => 'AUTH_custom_paystack_token',
            'email' => 'passenger@example.com',
            'amount' => 500.0,
            'currency' => 'NGN',
            'tx_ref' => 'ETIGO-TIP-101',
        ];

        $primary->shouldNotReceive('chargeWithToken');

        $fallback->shouldReceive('chargeWithToken')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 'successful',
                'transaction_id' => 'pstk_tx_101',
                'amount' => 500.0,
            ]);

        $gateway = new FallbackPaymentGateway($primary, $fallback);
        $result = $gateway->chargeWithToken($payload);

        expect($result['status'])->toBe('successful')
            ->and($result['transaction_id'])->toBe('pstk_tx_101');
    });

    it('tries primary first and succeeds for unrecognized token prefix', function () {
        $primary = Mockery::mock(PaymentGateway::class);
        $fallback = Mockery::mock(PaymentGateway::class);

        $payload = [
            'token' => 'custom_token_format_999',
            'email' => 'user@example.com',
            'amount' => 200.0,
            'currency' => 'NGN',
            'tx_ref' => 'ETIGO-202',
        ];

        $primary->shouldReceive('chargeWithToken')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 'successful',
                'transaction_id' => 'custom_tx_202',
                'amount' => 200.0,
            ]);

        $fallback->shouldNotReceive('chargeWithToken');

        $gateway = new FallbackPaymentGateway($primary, $fallback);
        $result = $gateway->chargeWithToken($payload);

        expect($result['status'])->toBe('successful')
            ->and($result['transaction_id'])->toBe('custom_tx_202');
    });

    it('falls back to secondary gateway when primary fails for unrecognized token prefix', function () {
        $primary = Mockery::mock(PaymentGateway::class);
        $fallback = Mockery::mock(PaymentGateway::class);

        $payload = [
            'token' => 'custom_token_format_999',
            'email' => 'user@example.com',
            'amount' => 200.0,
            'currency' => 'NGN',
            'tx_ref' => 'ETIGO-203',
        ];

        $primary->shouldReceive('chargeWithToken')
            ->once()
            ->with($payload)
            ->andThrow(new RuntimeException('Network timeout on primary'));

        $fallback->shouldReceive('chargeWithToken')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 'successful',
                'transaction_id' => 'fallback_tx_203',
                'amount' => 200.0,
            ]);

        $gateway = new FallbackPaymentGateway($primary, $fallback);
        $result = $gateway->chargeWithToken($payload);

        expect($result['status'])->toBe('successful')
            ->and($result['transaction_id'])->toBe('fallback_tx_203');
    });
});

describe('FallbackPaymentGateway failover mechanisms', function () {
    it('falls back on initializePayment failure', function () {
        $primary = Mockery::mock(PaymentGateway::class);
        $fallback = Mockery::mock(PaymentGateway::class);

        $payload = [
            'tx_ref' => 'REF-001',
            'amount' => 5000.0,
            'currency' => 'NGN',
            'redirect_url' => 'https://example.com/callback',
            'customer_email' => 'user@example.com',
            'customer_name' => 'John Doe',
        ];

        $primary->shouldReceive('initializePayment')
            ->once()
            ->with($payload)
            ->andThrow(new RuntimeException('Primary gateway error'));

        $fallback->shouldReceive('initializePayment')
            ->once()
            ->with($payload)
            ->andReturn([
                'payment_link' => 'https://checkout.flutterwave.com/v3/pay/xyz',
                'tx_ref' => 'REF-001',
            ]);

        $gateway = new FallbackPaymentGateway($primary, $fallback);
        $result = $gateway->initializePayment($payload);

        expect($result['payment_link'])->toBe('https://checkout.flutterwave.com/v3/pay/xyz')
            ->and($result['tx_ref'])->toBe('REF-001');
    });

    it('falls back on verifyTransaction failure', function () {
        $primary = Mockery::mock(PaymentGateway::class);
        $fallback = Mockery::mock(PaymentGateway::class);

        $primary->shouldReceive('verifyTransaction')
            ->once()
            ->with('tx_12345')
            ->andThrow(new RuntimeException('Transaction not found on primary'));

        $fallback->shouldReceive('verifyTransaction')
            ->once()
            ->with('tx_12345')
            ->andReturn([
                'status' => 'successful',
                'tx_ref' => 'REF-001',
                'transaction_id' => 'tx_12345',
                'amount' => 5000.0,
                'currency' => 'NGN',
                'payment_type' => 'card',
                'card_last_four' => '1234',
                'card_brand' => 'mastercard',
                'authorization_code' => 'FLW_token_12345',
            ]);

        $gateway = new FallbackPaymentGateway($primary, $fallback);
        $result = $gateway->verifyTransaction('tx_12345');

        expect($result['status'])->toBe('successful')
            ->and($result['authorization_code'])->toBe('FLW_token_12345');
    });

    it('falls back on refund failure', function () {
        $primary = Mockery::mock(PaymentGateway::class);
        $fallback = Mockery::mock(PaymentGateway::class);

        $primary->shouldReceive('refund')
            ->once()
            ->with('tx_12345', 1000.0)
            ->andThrow(new RuntimeException('Refund failed on primary'));

        $fallback->shouldReceive('refund')
            ->once()
            ->with('tx_12345', 1000.0)
            ->andReturn([
                'status' => 'successful',
                'refund_id' => 'ref_123',
                'amount_refunded' => 1000.0,
            ]);

        $gateway = new FallbackPaymentGateway($primary, $fallback);
        $result = $gateway->refund('tx_12345', 1000.0);

        expect($result['status'])->toBe('successful')
            ->and($result['refund_id'])->toBe('ref_123');
    });

    it('falls back on createCustomer failure', function () {
        $primary = Mockery::mock(PaymentGateway::class);
        $fallback = Mockery::mock(PaymentGateway::class);

        $primary->shouldReceive('createCustomer')
            ->once()
            ->with('user-uuid')
            ->andThrow(new RuntimeException('Primary down'));

        $fallback->shouldReceive('createCustomer')
            ->once()
            ->with('user-uuid')
            ->andReturn([
                'customer_id' => 'cust_123',
                'customer_code' => 'code_123',
            ]);

        $gateway = new FallbackPaymentGateway($primary, $fallback);
        $result = $gateway->createCustomer('user-uuid');

        expect($result['customer_id'])->toBe('cust_123');
    });
});
