<?php

namespace App\Gateways;

use App\Contracts\FlutterwaveWalletGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FakeFlutterwaveWalletGateway implements FlutterwaveWalletGateway
{
    public function initializePayment(array $data): array
    {
        Log::info('[FakeFlutterwaveWallet] initializePayment', $data);

        $txRef = $data['tx_ref'] ?? 'fake-'.Str::random(12);

        return [
            'link' => "https://checkout.flutterwave.com/v3/hosted/pay/fake-{$txRef}",
            'tx_ref' => $txRef,
        ];
    }

    public function verifyTransaction(string $transactionId): array
    {
        Log::info('[FakeFlutterwaveWallet] verifyTransaction', ['transaction_id' => $transactionId]);

        return [
            'status' => 'successful',
            'amount' => 100000,
            'tx_ref' => 'TOPUP-FAKE-'.Str::random(8),
        ];
    }

    public function initiateTransfer(array $data): array
    {
        Log::info('[FakeFlutterwaveWallet] initiateTransfer', $data);

        return [
            'id' => random_int(100000, 999999),
            'reference' => $data['reference'] ?? 'fake-trf-'.Str::random(8),
            'status' => 'NEW',
        ];
    }

    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        Log::info('[FakeFlutterwaveWallet] resolveAccountNumber', compact('accountNumber', 'bankCode'));

        return [
            'account_number' => $accountNumber,
            'account_name' => 'FAKE TEST ACCOUNT',
        ];
    }

    public function listBanks(): array
    {
        return [
            ['id' => 1, 'code' => '044', 'name' => 'Access Bank'],
            ['id' => 2, 'code' => '063', 'name' => 'Access Bank (Diamond)'],
            ['id' => 3, 'code' => '050', 'name' => 'Ecobank Nigeria'],
            ['id' => 4, 'code' => '070', 'name' => 'Fidelity Bank'],
            ['id' => 5, 'code' => '011', 'name' => 'First Bank of Nigeria'],
            ['id' => 6, 'code' => '058', 'name' => 'Guaranty Trust Bank'],
            ['id' => 7, 'code' => '030', 'name' => 'Heritage Bank'],
            ['id' => 8, 'code' => '082', 'name' => 'Keystone Bank'],
            ['id' => 9, 'code' => '076', 'name' => 'Polaris Bank'],
            ['id' => 10, 'code' => '039', 'name' => 'Stanbic IBTC Bank'],
            ['id' => 11, 'code' => '232', 'name' => 'Sterling Bank'],
            ['id' => 12, 'code' => '032', 'name' => 'Union Bank of Nigeria'],
            ['id' => 13, 'code' => '033', 'name' => 'United Bank For Africa'],
            ['id' => 14, 'code' => '215', 'name' => 'Unity Bank'],
            ['id' => 15, 'code' => '035', 'name' => 'Wema Bank'],
            ['id' => 16, 'code' => '057', 'name' => 'Zenith Bank'],
        ];
    }
}
