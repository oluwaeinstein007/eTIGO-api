<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use Illuminate\Support\Facades\Log;

class FallbackPaymentGateway implements PaymentGateway
{
    public function __construct(
        private PaymentGateway $primary,
        private PaymentGateway $fallback,
    ) {}

    public function createCustomer(string $userId): array
    {
        try {
            return $this->primary->createCustomer($userId);
        } catch (\Throwable $e) {
            Log::warning('Primary payment gateway createCustomer failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallback->createCustomer($userId);
        }
    }

    public function initializePayment(array $data): array
    {
        try {
            return $this->primary->initializePayment($data);
        } catch (\Throwable $e) {
            Log::warning('Primary payment gateway initializePayment failed, using fallback', [
                'error' => $e->getMessage(),
                'tx_ref' => $data['tx_ref'] ?? null,
            ]);

            return $this->fallback->initializePayment($data);
        }
    }

    public function verifyTransaction(string $transactionId): array
    {
        try {
            return $this->primary->verifyTransaction($transactionId);
        } catch (\Throwable $e) {
            Log::info('Primary payment gateway verifyTransaction failed, trying fallback', [
                'transaction_id' => $transactionId,
            ]);

            return $this->fallback->verifyTransaction($transactionId);
        }
    }

    public function chargeWithToken(array $data): array
    {
        $token = trim((string) ($data['token'] ?? ''));
        $targetGateway = $this->resolveGatewayForToken($token);

        if ($targetGateway !== null) {
            return $targetGateway->chargeWithToken($data);
        }

        try {
            return $this->primary->chargeWithToken($data);
        } catch (\Throwable $e) {
            Log::warning('Primary payment gateway chargeWithToken failed, trying fallback', [
                'error' => $e->getMessage(),
                'tx_ref' => $data['tx_ref'] ?? null,
            ]);

            return $this->fallback->chargeWithToken($data);
        }
    }

    private function resolveGatewayForToken(string $token): ?PaymentGateway
    {
        if ($this->isFlutterwaveToken($token)) {
            if ($this->primary instanceof FlutterwavePaymentGateway) {
                return $this->primary;
            }

            if ($this->fallback instanceof FlutterwavePaymentGateway) {
                return $this->fallback;
            }

            if ($this->primary instanceof PaystackPaymentGateway) {
                return $this->fallback;
            }

            return $this->fallback;
        }

        if ($this->isPaystackToken($token)) {
            if ($this->fallback instanceof PaystackPaymentGateway && ! ($this->primary instanceof PaystackPaymentGateway)) {
                return $this->fallback;
            }

            return $this->primary;
        }

        return null;
    }

    private function isFlutterwaveToken(string $token): bool
    {
        return str_starts_with(strtoupper($token), 'FLW');
    }

    private function isPaystackToken(string $token): bool
    {
        return str_starts_with(strtoupper($token), 'AUTH');
    }

    public function refund(string $transactionId, float $amount): array
    {
        try {
            return $this->primary->refund($transactionId, $amount);
        } catch (\Throwable $e) {
            Log::warning('Primary payment gateway refund failed, trying fallback', [
                'transaction_id' => $transactionId,
            ]);

            return $this->fallback->refund($transactionId, $amount);
        }
    }
}
