<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

class LogSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): bool
    {
        Log::info('SMS sent', [
            'phone' => $phone,
            'message' => $message,
        ]);

        return true;
    }

    /**
     * Nothing reaches the phone without a messaging provider, so this says
     * no channel accepted the message; a third-party booker is then shown
     * the PIN instead of waiting for a message that never arrives.
     */
    public function deliver(string $phone, string $message): ?string
    {
        $this->send($phone, $message);

        return null;
    }

    public function sendOtp(string $phone, string $code, string $message): bool
    {
        return $this->send($phone, $message);
    }
}
