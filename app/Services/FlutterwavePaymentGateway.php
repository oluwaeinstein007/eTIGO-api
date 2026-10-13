<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FlutterwavePaymentGateway implements PaymentGateway
{
    public function __construct(
        private string $secretKey,
        private string $baseUrl = 'https://api.flutterwave.com/v3',
    ) {}

    public function createCustomer(string $userId): array
    {
        $user = User::findOrFail($userId);

        return [
            'customer_id' => "flw_cust_{$userId}",
            'customer_code' => "FLW_CUS_{$userId}",
        ];
    }

    public function initializePayment(array $data): array
    {
        $payload = [
            'tx_ref' => $data['tx_ref'],
            'amount' => (float) $data['amount'],
            'currency' => $data['currency'] ?? 'NGN',
            'redirect_url' => $data['redirect_url'],
            'customer' => [
                'email' => $data['customer_email'],
            ],
        ];

        if (! empty($data['channels'])) {
            $payload['payment_options'] = implode(',', $data['channels']);
        }

        $response = $this->request('post', '/payments', $payload);

        return [
            'payment_link' => $response['data']['link'],
            'tx_ref' => $data['tx_ref'],
        ];
    }

    public function verifyTransaction(string $transactionId): array
    {
        $response = $this->request('get', "/transactions/{$transactionId}/verify");

        $data = $response['data'];
        $card = $data['card'] ?? [];

        return [
            'status' => $data['status'] === 'successful' ? 'successful' : $data['status'],
            'tx_ref' => $data['tx_ref'],
            'transaction_id' => (string) $data['id'],
            'amount' => (float) $data['amount'],
            'currency' => $data['currency'],
            'payment_type' => $data['payment_type'] ?? 'card',
            'card_last_four' => $card['last_4digits'] ?? null,
            'card_brand' => $card['type'] ?? null,
            'authorization_code' => $card['token'] ?? null,
            'provider' => 'flutterwave',
        ];
    }

    public function chargeWithToken(array $data): array
    {
        $response = $this->request('post', '/tokenized-charges', [
            'token' => $data['token'],
            'email' => $data['email'],
            'amount' => (float) $data['amount'],
            'currency' => $data['currency'] ?? 'NGN',
            'tx_ref' => $data['tx_ref'],
            'country' => 'NG',
        ]);

        return [
            'status' => $response['data']['status'] === 'successful' ? 'successful' : $response['data']['status'],
            'transaction_id' => (string) $response['data']['id'],
            'amount' => (float) $response['data']['amount'],
        ];
    }

    public function refund(string $transactionId, float $amount): array
    {
        $response = $this->request('post', "/transactions/{$transactionId}/refund", [
            'amount' => $amount,
        ]);

        return [
            'status' => $response['data']['status'],
            'refund_id' => (string) $response['data']['id'],
            'amount_refunded' => (float) $response['data']['amount_refunded'],
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

        if (($body['status'] ?? '') !== 'success') {
            throw new RuntimeException('Flutterwave API error: '.($body['message'] ?? 'Unknown error'));
        }

        return $body;
    }
}
