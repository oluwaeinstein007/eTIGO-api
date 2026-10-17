<?php

namespace App\Contracts;

interface WalletGateway
{
    /**
     * $data holds tx_ref, amount (naira), currency, redirect_url, customer
     * (email, name), meta and optionally channels (default card, bank
     * transfer and USSD).
     *
     * @return array{link: string, tx_ref: string}
     */
    public function initializePayment(array $data): array;

    /**
     * The amount is in kobo. The card fields are null unless the payment was
     * made by card; reusable says whether the authorization can be charged
     * again, and card_signature identifies the physical card. customer_email
     * is the email the payment was made under, which charging the
     * authorization later requires.
     *
     * @return array{status: string, amount: int, tx_ref: string, currency?: string, channel?: ?string, card_last_four?: ?string, card_brand?: ?string, card_expiry_month?: ?int, card_expiry_year?: ?int, authorization_code?: ?string, card_signature?: ?string, reusable?: bool, customer_email?: ?string}
     */
    public function verifyTransaction(string $transactionId): array;

    /**
     * Register a bank account as a transfer recipient and return its code.
     */
    public function createTransferRecipient(string $accountNumber, string $bankCode, string $currency = 'NGN'): string;

    /**
     * Send money to a recipient from createTransferRecipient(). $data holds
     * recipient_code, amount (naira), reference, narration and currency.
     *
     * @return array{id: int|string, reference: string, status: string}
     */
    public function initiateTransfer(array $data): array;

    /**
     * Look up a transfer by our reference; null when the provider has none.
     * The amount is in kobo.
     *
     * @return array{id: int|string, reference: string, status: string, amount: int}|null
     */
    public function verifyTransfer(string $reference): ?array;

    /** @return array{account_number: string, account_name: string} */
    public function resolveAccountNumber(string $accountNumber, string $bankCode): array;

    /** @return array{id: int|string, code: string, name: string}[] */
    public function listBanks(): array;
}
