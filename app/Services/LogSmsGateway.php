<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

class LogSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): bool
    {
        Log::channel('single')->info('SMS sent', [
            'phone' => $phone,
            'message' => $message,
        ]);

        return true;
    }
}
