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

    /**
     * @return array{customer_id: string, customer_code: string}
     */
    public function createCustomer(int $userId): array
    {
        $user = User::findOrFail($userId);

        $response = $this->request('post', '/customers', [
            'email' => $user->email,
            'fullname' => "{$user->first_name} {$user->last_name}",
            'phone' => $user->phone,
        ]);

        return [
            'customer_id' => (string) $response['data']['id'],
            'customer_code' => $response['data']['customer_code'] ?? (string) $response['data']['id'],
        ];
    }

    /**
     * @param  array{tx_ref: string, amount: float, currency: string, redirect_url: string, customer_email: string, customer_name: string}  $data
     * @return array{payment_link: string, tx_ref: string}
     */
    public function initializePayment(array $data): array
    {
        $response = $this->request('post', '/payments', [
            'tx_ref' => $data['tx_ref'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'redirect_url' => $data['redirect_url'],
            'customer' => [
                'email' => $data['customer_email'],
                'name' => $data['customer_name'],
            ],
            'payment_options' => 'card',
        ]);

        return [
            'payment_link' => $response['data']['link'],
            'tx_ref' => $data['tx_ref'],
        ];
    }

    /**
     * @return array{status: string, tx_ref: string, transaction_id: string, amount: float, currency: string, payment_type: string, card_last_four: ?string, card_brand: ?string}
     */
    public function verifyTransaction(string $transactionId): array
    {
        $response = $this->request('get', "/transactions/{$transactionId}/verify");

        $data = $response['data'];
        $card = $data['card'] ?? [];

        return [
            'status' => $data['status'],
            'tx_ref' => $data['tx_ref'],
            'transaction_id' => (string) $data['id'],
            'amount' => (float) $data['amount'],
            'currency' => $data['currency'],
            'payment_type' => $data['payment_type'] ?? 'card',
            'card_last_four' => $card['last_4digits'] ?? null,
            'card_brand' => $card['type'] ?? null,
        ];
    }

    /**
     * @param  array{token: string, email: string, amount: float, currency: string, tx_ref: string}  $data
     * @return array{status: string, transaction_id: string, amount: float}
     */
    public function chargeWithToken(array $data): array
    {
        $response = $this->request('post', '/tokenized-charges', [
            'token' => $data['token'],
            'email' => $data['email'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'tx_ref' => $data['tx_ref'],
        ]);

        return [
            'status' => $response['data']['status'],
            'transaction_id' => (string) $response['data']['id'],
            'amount' => (float) $response['data']['amount'],
        ];
    }

    /**
     * @return array{status: string, refund_id: string, amount_refunded: float}
     */
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

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $data = []): array
    {
        $response = Http::withToken($this->secretKey)
            ->acceptJson()
            ->{$method}("{$this->baseUrl}{$path}", $data);

        $response->throw();

        $body = $response->json();

        if (($body['status'] ?? '') !== 'success') {
            throw new RuntimeException('Flutterwave API error: '.($body['message'] ?? 'Unknown error'));
        }

        return $body;
    }
}
