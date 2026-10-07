<?php

namespace App\Services;

use App\Contracts\PushNotificationGateway;
use Illuminate\Support\Facades\Log;

class LogPushGateway implements PushNotificationGateway
{
    /**
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $notification
     */
    public function sendToUser(string $userId, array $notification): void
    {
        Log::info('Push notification (user)', [
            'user_id' => $userId,
            'notification' => $notification,
        ]);
    }

    /**
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     */
    public function sendToDevice(string $token, array $payload): void
    {
        Log::info('Push notification (device)', [
            'token' => $token,
            'payload' => $payload,
        ]);
    }

    /**
     * @param  array<string>  $tokens
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     * @return array{success_count: int, failure_count: int}
     */
    public function sendToDevices(array $tokens, array $payload): array
    {
        Log::info('Push notification (multicast)', [
            'tokens' => $tokens,
            'payload' => $payload,
        ]);

        return [
            'success_count' => count($tokens),
            'failure_count' => 0,
        ];
    }
}
