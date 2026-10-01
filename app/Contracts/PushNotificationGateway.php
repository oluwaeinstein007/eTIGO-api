<?php

namespace App\Contracts;

interface PushNotificationGateway
{
    /**
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $notification
     */
    public function sendToUser(int $userId, array $notification): void;

    /**
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     */
    public function sendToDevice(string $token, array $payload): void;

    /**
     * @param  array<string>  $tokens
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     * @return array{success_count: int, failure_count: int}
     */
    public function sendToDevices(array $tokens, array $payload): array;
}
