<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackPaymentGateway implements PaymentGateway
{
    public function __construct(
        private string $secretKey,
        private string $baseUrl = 'https://api.paystack.co',
    ) {}

    public function createCustomer(string $userId): array
    {
        $user = User::findOrFail($userId);

        $response = $this->request('post', '/customer', [
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
        ]);

        return [
            'customer_id' => (string) $response['data']['id'],
            'customer_code' => $response['data']['customer_code'],
        ];
    }

    public function initializePayment(array $data): array
    {
        $response = $this->request('post', '/transaction/initialize', [
            'reference' => $data['tx_ref'],
            'amount' => (int) ($data['amount'] * 100),
            'currency' => $data['currency'] ?? 'NGN',
            'callback_url' => $data['redirect_url'],
            'email' => $data['customer_email'],
            'channels' => ['card', 'bank_transfer', 'ussd'],
        ]);

        return [
            'payment_link' => $response['data']['authorization_url'],
            'tx_ref' => $data['tx_ref'],
        ];
    }

    public function verifyTransaction(string $transactionId): array
    {
        $response = $this->request('get', "/transaction/verify/{$transactionId}");

        $data = $response['data'];
        $authorization = $data['authorization'] ?? [];

        return [
            'status' => $data['status'] === 'success' ? 'successful' : $data['status'],
            'tx_ref' => $data['reference'],
            'transaction_id' => (string) $data['id'],
            'amount' => (float) ($data['amount'] / 100),
            'currency' => $data['currency'],
            'payment_type' => $authorization['channel'] ?? 'card',
            'card_last_four' => $authorization['last4'] ?? null,
            'card_brand' => $authorization['brand'] ?? null,
        ];
    }

    public function chargeWithToken(array $data): array
    {
        $response = $this->request('post', '/transaction/charge_authorization', [
            'authorization_code' => $data['token'],
            'email' => $data['email'],
            'amount' => (int) ($data['amount'] * 100),
            'currency' => $data['currency'] ?? 'NGN',
            'reference' => $data['tx_ref'],
        ]);

        return [
            'status' => $response['data']['status'] === 'success' ? 'successful' : $response['data']['status'],
            'transaction_id' => (string) $response['data']['id'],
            'amount' => (float) ($response['data']['amount'] / 100),
        ];
    }

    public function refund(string $transactionId, float $amount): array
    {
        $response = $this->request('post', '/refund', [
            'transaction' => $transactionId,
            'amount' => (int) ($amount * 100),
        ]);

        return [
            'status' => $response['data']['status'],
            'refund_id' => (string) $response['data']['id'],
            'amount_refunded' => (float) ($response['data']['amount'] / 100),
        ];
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $data = []): array
    {
        $response = Http::withToken($this->secretKey)
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5)
            ->{$method}("{$this->baseUrl}{$path}", $data);

        $response->throw();

        $body = $response->json();

        if (($body['status'] ?? false) !== true) {
            throw new RuntimeException('Paystack API error: ' . ($body['message'] ?? 'Unknown error'));
        }

        return $body;
    }
}
