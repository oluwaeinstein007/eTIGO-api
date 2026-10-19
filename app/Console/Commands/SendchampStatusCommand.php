<?php

namespace App\Console\Commands;

use App\Services\SendchampGateway;
use Illuminate\Console\Command;

class SendchampStatusCommand extends Command
{
    protected $signature = 'sendchamp:status';

    protected $description = 'Show the Sendchamp messaging setup and wallet balance';

    public function handle(): int
    {
        if (! config('services.sendchamp.access_key')) {
            $this->error('SENDCHAMP_ACCESS_KEY is not set: messages are only written to the log.');

            return self::FAILURE;
        }

        $gateway = new SendchampGateway;
        $config = config('services.sendchamp');

        $this->table(['Setting', 'Value'], [
            ['SMS sender name', $config['sender_name']],
            ['SMS route (Nigeria)', $config['sms_route']],
            ['WhatsApp sender', $config['whatsapp_sender'] ?: '(not set)'],
            ['WhatsApp notification template', $config['whatsapp_template'] ?: '(not set)'],
            ['WhatsApp OTP template', $config['whatsapp_otp_template'] ?: '(not set)'],
            ['Notifications go by', $gateway->whatsAppEnabled() ? 'WhatsApp, then SMS' : 'SMS only'],
            ['OTP codes go by', $gateway->whatsAppOtpEnabled() ? 'WhatsApp, then SMS' : 'SMS only'],
            ['SOS safety number', config('sos.safety_number') ?: '(not set: SOS alerts are skipped)'],
        ]);

        $wallet = $gateway->walletBalance();

        if ($wallet === null) {
            $this->error('Could not read the wallet: '.$gateway->lastError());

            return self::FAILURE;
        }

        $this->info("Account: {$wallet['business_name']}. Wallet balance: {$wallet['wallet_balance']}.");

        if ((float) $wallet['wallet_balance'] <= 0) {
            $this->warn('The wallet is empty: Sendchamp refuses every message until it is funded.');
        }

        return self::SUCCESS;
    }
}
