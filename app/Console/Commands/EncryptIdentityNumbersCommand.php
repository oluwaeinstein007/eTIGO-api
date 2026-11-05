<?php

namespace App\Console\Commands;

use App\Support\IdentityNumberEncryption;
use Illuminate\Console\Command;

/**
 * Encrypts any driver ID number or KYC match detail still stored as plain
 * text. The deploy runs it after the new code goes live, for values the old
 * code wrote between the migration and the reload. Safe to run any time.
 */
class EncryptIdentityNumbersCommand extends Command
{
    protected $signature = 'drivers:encrypt-identity-numbers';

    protected $description = 'Encrypt driver ID numbers and KYC match details still stored as plain text';

    public function handle(): int
    {
        $changed = IdentityNumberEncryption::encryptAll();

        $this->info("{$changed} row(s) encrypted.");

        return self::SUCCESS;
    }
}
