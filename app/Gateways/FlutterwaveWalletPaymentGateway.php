<?php

namespace App\Gateways;

use App\Contracts\FlutterwaveWalletGateway;
use Illuminate\Support\Facades\Http;

class FlutterwaveWalletPaymentGateway implements FlutterwaveWalletGateway
{
    private string $baseUrl;

    public function __construct(private string $secretKey)
    {
        $this->baseUrl = config('wallet.flutterwave.base_url', 'https://api.flutterwave.com/v3');
    }

    public function initializePayment(array $data): array
    {
        $response = $this->request('post', '/payments', $data);

        return [
            'link' => $response['data']['link'],
            'tx_ref' => $data['tx_ref'],
        ];
    }

    public function verifyTransaction(string $transactionId): array
    {
        $response = $this->request('get', "/transactions/{$transactionId}/verify");

        $data = $response['data'];

        return [
            'status' => $data['status'],
            'amount' => (int) $data['amount'],
            'tx_ref' => $data['tx_ref'],
        ];
    }

    public function initiateTransfer(array $data): array
    {
        $response = $this->request('post', '/transfers', $data);

        return [
            'id' => $response['data']['id'],
            'reference' => $response['data']['reference'] ?? $data['reference'],
            'status' => $response['data']['status'],
        ];
    }

    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        $response = $this->request('post', '/accounts/resolve', [
            'account_number' => $accountNumber,
            'account_bank' => $bankCode,
        ]);

        return [
            'account_number' => $response['data']['account_number'],
            'account_name' => $response['data']['account_name'],
        ];
    }

    public function listBanks(): array
    {
        $response = $this->request('get', '/banks/NG');

        return $response['data'];
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $http = Http::withToken($this->secretKey)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5);

        $response = $method === 'get'
            ? $http->get($path, $data)
            : $http->post($path, $data);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Flutterwave API error: {$response->status()} - ".($response->json('message') ?? $response->body())
            );
        }

        return $response->json();
    }
}
