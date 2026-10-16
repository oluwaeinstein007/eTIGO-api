<?php

namespace App\Notifications\Channels;

use App\Contracts\SmsGateway;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Sends a notification's toSms() text to the notifiable's phone (WhatsApp with
 * SMS fallback through Termii). It ignores notification preferences, so keep it
 * for security alerts the account holder must receive.
 */
class SmsChannel
{
    public function __construct(
        private SmsGateway $gateway,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable->phone) {
            return;
        }

        if (! $this->gateway->send($notifiable->phone, $notification->toSms($notifiable))) {
            throw new RuntimeException('SMS delivery failed for '.$notification::class);
        }
    }
}
