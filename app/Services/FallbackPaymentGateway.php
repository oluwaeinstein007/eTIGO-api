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
        return $this->primary->chargeWithToken($data);
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
