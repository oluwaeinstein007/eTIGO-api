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

    /**
     * Send a one-time code. WhatsApp only accepts codes through an
     * authentication template, so the code travels on its own there;
     * $message is the full text used for SMS.
     */
    public function sendOtp(string $phone, string $code, string $message): bool;
}
