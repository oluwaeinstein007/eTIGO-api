<?php

namespace App\Contracts;

interface FlutterwaveWalletGateway
{
    /** @return array{link: string, tx_ref: string} */
    public function initializePayment(array $data): array;

    /** @return array{status: string, amount: int, tx_ref: string} */
    public function verifyTransaction(string $transactionId): array;

    /** @return array{id: int, reference: string, status: string} */
    public function initiateTransfer(array $data): array;

    /** @return array{account_number: string, account_name: string} */
    public function resolveAccountNumber(string $accountNumber, string $bankCode): array;

    /** @return array{id: int, code: string, name: string}[] */
    public function listBanks(): array;
}
