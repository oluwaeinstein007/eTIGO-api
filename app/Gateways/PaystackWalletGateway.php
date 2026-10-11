<?php

namespace App\Gateways;

use App\Contracts\WalletGateway;
use Illuminate\Support\Facades\Http;

class PaystackWalletGateway implements WalletGateway
{
    private string $baseUrl;

    public function __construct(private string $secretKey)
    {
        $this->baseUrl = config('wallet.paystack.base_url', 'https://api.paystack.co');
    }

    public function initializePayment(array $data): array
    {
        $amount = isset($data['amount']) ? (int) ($data['amount'] * 100) : 0;

        $payload = [
            'reference' => $data['tx_ref'],
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'NGN',
            'email' => $data['customer']['email'] ?? $data['email'] ?? '',
            'callback_url' => $data['redirect_url'] ?? '',
            'channels' => ['card', 'bank_transfer', 'ussd'],
        ];

        if (! empty($data['meta'])) {
            $payload['metadata'] = $data['meta'];
        }

        $response = $this->request('post', '/transaction/initialize', $payload);

        return [
            'link' => $response['data']['authorization_url'],
            'tx_ref' => $data['tx_ref'],
        ];
    }

    public function verifyTransaction(string $transactionId): array
    {
        $response = $this->request('get', "/transaction/verify/{$transactionId}");

        $data = $response['data'];

        return [
            'status' => $data['status'] === 'success' ? 'successful' : $data['status'],
            'amount' => (int) $data['amount'],
            'tx_ref' => $data['reference'],
        ];
    }

    public function initiateTransfer(array $data): array
    {
        $recipientCode = $data['recipient_code'] ?? null;

        if (! $recipientCode) {
            $recipientCode = $this->createTransferRecipient(
                $data['account_number'],
                $data['account_bank'],
                $data['currency'] ?? 'NGN',
            );
        }

        $response = $this->request('post', '/transfer', [
            'source' => 'balance',
            'reason' => $data['narration'] ?? '',
            'amount' => (int) ($data['amount'] * 100),
            'recipient' => $recipientCode,
            'reference' => $data['reference'],
        ]);

        return [
            'id' => $response['data']['id'],
            'reference' => $response['data']['reference'] ?? $data['reference'],
            'status' => $response['data']['status'],
        ];
    }

    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        $response = $this->request('get', '/bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]);

        return [
            'account_number' => $response['data']['account_number'],
            'account_name' => $response['data']['account_name'],
        ];
    }

    public function listBanks(): array
    {
        $response = $this->request('get', '/bank', [
            'country' => 'nigeria',
            'perPage' => 100,
        ]);

        return array_map(fn ($bank) => [
            'id' => $bank['id'],
            'code' => $bank['code'],
            'name' => $bank['name'],
        ], $response['data']);
    }

    private function createTransferRecipient(string $accountNumber, string $bankCode, string $currency = 'NGN'): string
    {
        $type = $bankCode === 'opay' ? 'mobile_money' : 'nuban';

        $response = $this->request('post', '/transferrecipient', [
            'type' => $type,
            'name' => $accountNumber,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => $currency,
        ]);

        return $response['data']['recipient_code'];
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
                "Paystack API error: {$response->status()} - " . ($response->json('message') ?? $response->body())
            );
        }

        return $response->json();
    }
}
