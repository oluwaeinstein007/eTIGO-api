<?php

namespace App\Contracts;

interface SmsGateway
{
    public function send(string $phone, string $message): bool;

    /**
     * Send by WhatsApp, falling back to SMS, and say which channel accepted
     * the message: 'whatsapp', 'sms', or null when both refused it.
     */
    public function deliver(string $phone, string $message): ?string;
}
