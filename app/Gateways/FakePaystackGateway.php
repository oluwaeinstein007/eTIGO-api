<?php

namespace App\Gateways;

use App\Contracts\PaystackGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FakePaystackGateway implements PaystackGateway
{
    public function initializeTransaction(array $data): array
    {
        Log::info('[FakePaystack] initializeTransaction', $data);

        $reference = $data['reference'] ?? 'fake-'.Str::random(12);

        return [
            'authorization_url' => "https://checkout.paystack.com/fake-{$reference}",
            'access_code' => 'fake-access-'.Str::random(8),
            'reference' => $reference,
        ];
    }

    public function verifyTransaction(string $reference): array
    {
        Log::info('[FakePaystack] verifyTransaction', ['reference' => $reference]);

        return [
            'status' => 'success',
            'amount' => 100000,
            'reference' => $reference,
            'authorization' => [
                'authorization_code' => 'AUTH_fake_'.Str::random(8),
                'card_type' => 'visa',
                'last4' => '4081',
                'exp_month' => '12',
                'exp_year' => '2030',
                'bank' => 'TEST BANK',
            ],
        ];
    }

    public function initiateTransfer(array $data): array
    {
        Log::info('[FakePaystack] initiateTransfer', $data);

        return [
            'transfer_code' => 'TRF_fake_'.Str::random(8),
            'id' => random_int(100000, 999999),
            'reference' => $data['reference'] ?? 'fake-trf-'.Str::random(8),
        ];
    }

    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        Log::info('[FakePaystack] resolveAccountNumber', compact('accountNumber', 'bankCode'));

        return [
            'account_number' => $accountNumber,
            'account_name' => 'FAKE TEST ACCOUNT',
            'bank_id' => 1,
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

    public function createTransferRecipient(array $data): array
    {
        Log::info('[FakePaystack] createTransferRecipient', $data);

        return [
            'recipient_code' => 'RCP_fake_'.Str::random(8),
        ];
    }
}
