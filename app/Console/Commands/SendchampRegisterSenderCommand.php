<?php

namespace App\Console\Commands;

use App\Services\SendchampGateway;
use Illuminate\Console\Command;

class SendchampRegisterSenderCommand extends Command
{
    protected $signature = 'sendchamp:register-sender
        {name=Etigo : Sender name shown on the SMS (up to 11 letters or digits)}
        {--use-case=Transactional : Transactional, Marketing, or "Transactional & Marketing"}
        {--sample=Your E-tiGo verification code is 123456. It expires in 5 minutes. Do not share it with anyone. : A sample message for the reviewers}';

    protected $description = 'Ask Sendchamp to approve an SMS sender name';

    private const USE_CASES = ['Transactional', 'Marketing', 'Transactional & Marketing'];

    public function handle(): int
    {
        if (! config('services.sendchamp.access_key')) {
            $this->error('SENDCHAMP_ACCESS_KEY is not set.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        $useCase = (string) $this->option('use-case');

        if (! preg_match('/^[A-Za-z0-9 ]{3,11}$/', $name)) {
            $this->error('The sender name must be 3 to 11 letters, digits or spaces.');

            return self::FAILURE;
        }

        if (! in_array($useCase, self::USE_CASES, true)) {
            $this->error('The use case must be one of: '.implode(', ', self::USE_CASES).'.');

            return self::FAILURE;
        }

        $gateway = new SendchampGateway;
        $sender = $gateway->registerSenderId($name, (string) $this->option('sample'), $useCase);

        if ($sender === null) {
            $this->error('Sendchamp refused the request: '.$gateway->lastError());

            return self::FAILURE;
        }

        $this->info("Sender name '{$name}' submitted (status: ".($sender['status'] ?? 'pending').').');
        $this->line('Once Sendchamp approves it, set SENDCHAMP_SENDER_NAME='.$name.' and run php artisan config:cache.');

        return self::SUCCESS;
    }
}
