<?php

namespace App\Console\Commands;

use App\Services\SendchampGateway;
use Illuminate\Console\Command;

class SendchampTestCommand extends Command
{
    protected $signature = 'sendchamp:test
        {phone : Recipient in E.164 format, e.g. +2348012345678}
        {--channel=auto : auto (WhatsApp, then SMS) | sms | whatsapp | otp}';

    protected $description = 'Send a test message through Sendchamp (charged to the Sendchamp wallet)';

    public function handle(): int
    {
        if (! config('services.sendchamp.access_key')) {
            $this->error('SENDCHAMP_ACCESS_KEY is not set.');

            return self::FAILURE;
        }

        $phone = (string) $this->argument('phone');

        if (! preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
            $this->error('Give the number in E.164 format, e.g. +2348012345678.');

            return self::FAILURE;
        }

        $gateway = new SendchampGateway;
        $message = 'E-tiGo: this is a test message from '.config('app.name').' ('.app()->environment().').';

        $result = match ($this->option('channel')) {
            'auto' => $gateway->deliver($phone, $message),
            'sms' => $gateway->sendSms($phone, $message) ? 'sms' : null,
            'whatsapp' => $gateway->sendWhatsAppTemplate($phone, config('services.sendchamp.whatsapp_template') ?: null, ['1' => $message]) ? 'whatsapp' : null,
            'otp' => $gateway->sendOtp($phone, '123456', 'Your E-tiGo verification code is 123456 (test). Do not share it with anyone.') ? 'sent' : null,
            default => false,
        };

        if ($result === false) {
            $this->error('Unknown channel. Use auto, sms, whatsapp or otp.');

            return self::FAILURE;
        }

        if ($result === null) {
            $reason = $gateway->lastError() ?? 'WhatsApp needs SENDCHAMP_WHATSAPP_SENDER and the template code';
            $this->error("Not sent: {$reason}");

            return self::FAILURE;
        }

        $this->info("Accepted by Sendchamp ({$result}). Check the phone and the Sendchamp dashboard reports.");

        return self::SUCCESS;
    }
}
