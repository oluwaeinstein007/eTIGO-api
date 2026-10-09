<?php

namespace App\Contracts;

interface PaystackGateway
{
    /** @return array{authorization_url: string, access_code: string, reference: string} */
    public function initializeTransaction(array $data): array;

    /** @return array{status: string, amount: int, reference: string, authorization?: array} */
    public function verifyTransaction(string $reference): array;

    /** @return array{transfer_code: string, id: int, reference: string} */
    public function initiateTransfer(array $data): array;

    /** @return array{account_number: string, account_name: string, bank_id: int} */
    public function resolveAccountNumber(string $accountNumber, string $bankCode): array;

    /** @return array{id: int, code: string, name: string}[] */
    public function listBanks(): array;

    /** @return array{recipient_code: string} */
    public function createTransferRecipient(array $data): array;
}
