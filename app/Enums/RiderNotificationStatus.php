<?php

namespace App\Enums;

/**
 * Delivery of the driver-details message (with the PIN and/or tracking link)
 * to the rider of a third-party booking. `pending` until the message is
 * accepted by a channel; `failed` once WhatsApp and SMS both refused it, which
 * shows the PIN to the booker whatever the policy. `delivered` is reserved for
 * provider delivery receipts.
 */
enum RiderNotificationStatus: string
{
    case Pending = 'pending';
    case SentWhatsapp = 'sent_whatsapp';
    case SentSms = 'sent_sms';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public static function forChannel(string $channel): self
    {
        return $channel === 'whatsapp' ? self::SentWhatsapp : self::SentSms;
    }
}
