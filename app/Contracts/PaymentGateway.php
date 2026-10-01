<?php

namespace App\Contracts;

interface PaymentGateway
{
    /**
     * @return array{customer_id: string, customer_code: string}
     */
    public function createCustomer(int $userId): array;

    /**
     * @param  array{tx_ref: string, amount: float, currency: string, redirect_url: string, customer_email: string, customer_name: string}  $data
     * @return array{payment_link: string, tx_ref: string}
     */
    public function initializePayment(array $data): array;

    /**
     * @return array{status: string, tx_ref: string, transaction_id: string, amount: float, currency: string, payment_type: string, card_last_four: ?string, card_brand: ?string}
     */
    public function verifyTransaction(string $transactionId): array;

    /**
     * @param  array{token: string, email: string, amount: float, currency: string, tx_ref: string}  $data
     * @return array{status: string, transaction_id: string, amount: float}
     */
    public function chargeWithToken(array $data): array;

    /**
     * @return array{status: string, refund_id: string, amount_refunded: float}
     */
    public function refund(string $transactionId, float $amount): array;
}
