<?php

namespace App\Gateways;

use App\Contracts\PaystackGateway;
use Illuminate\Support\Facades\Http;

class PaystackPaymentGateway implements PaystackGateway
{
    private string $baseUrl;

    public function __construct(private string $secretKey)
    {
        $this->baseUrl = config('wallet.paystack.base_url', 'https://api.paystack.co');
    }

    public function initializeTransaction(array $data): array
    {
        $response = $this->request('post', '/transaction/initialize', $data);

        return $response['data'];
    }

    public function verifyTransaction(string $reference): array
    {
        $response = $this->request('get', "/transaction/verify/{$reference}");

        return $response['data'];
    }

    public function initiateTransfer(array $data): array
    {
        $response = $this->request('post', '/transfer', $data);

        return $response['data'];
    }

    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        $response = $this->request('get', '/bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]);

        return $response['data'];
    }

    public function listBanks(): array
    {
        $response = $this->request('get', '/bank', [
            'country' => 'nigeria',
            'perPage' => 100,
        ]);

        return $response['data'];
    }

    public function createTransferRecipient(array $data): array
    {
        $response = $this->request('post', '/transferrecipient', $data);

        return $response['data'];
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $http = Http::withToken($this->secretKey)
            ->baseUrl($this->baseUrl)
            ->acceptJson();

        $response = $method === 'get'
            ? $http->get($path, $data)
            : $http->post($path, $data);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Paystack API error: {$response->status()} - ".($response->json('message') ?? $response->body())
            );
        }

        return $response->json();
    }
}
