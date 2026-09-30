<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\User;
use Illuminate\Support\Str;

class FakePaymentGateway implements PaymentGateway
{
    /**
     * @return array{customer_id: string, customer_code: string}
     */
    public function createCustomer(int $userId): array
    {
        $user = User::findOrFail($userId);

        return [
            'customer_id' => "fake_cust_{$userId}",
            'customer_code' => "CUS_FAKE_{$userId}",
        ];
    }

    /**
     * @param  array{tx_ref: string, amount: float, currency: string, redirect_url: string, customer_email: string, customer_name: string}  $data
     * @return array{payment_link: string, tx_ref: string}
     */
    public function initializePayment(array $data): array
    {
        return [
            'payment_link' => "https://fake-checkout.test/pay/{$data['tx_ref']}",
            'tx_ref' => $data['tx_ref'],
        ];
    }

    /**
     * @return array{status: string, tx_ref: string, transaction_id: string, amount: float, currency: string, payment_type: string, card_last_four: ?string, card_brand: ?string}
     */
    public function verifyTransaction(string $transactionId): array
    {
        return [
            'status' => 'successful',
            'tx_ref' => 'fake_tx_'.Str::random(10),
            'transaction_id' => $transactionId,
            'amount' => 0.0,
            'currency' => 'NGN',
            'payment_type' => 'card',
            'card_last_four' => '4242',
            'card_brand' => 'VISA',
        ];
    }

    /**
     * @param  array{token: string, email: string, amount: float, currency: string, tx_ref: string}  $data
     * @return array{status: string, transaction_id: string, amount: float}
     */
    public function chargeWithToken(array $data): array
    {
        return [
            'status' => 'successful',
            'transaction_id' => 'fake_txn_'.Str::random(10),
            'amount' => $data['amount'],
        ];
    }

    /**
     * @return array{status: string, refund_id: string, amount_refunded: float}
     */
    public function refund(string $transactionId, float $amount): array
    {
        return [
            'status' => 'completed',
            'refund_id' => 'fake_ref_'.Str::random(10),
            'amount_refunded' => $amount,
        ];
    }
}
