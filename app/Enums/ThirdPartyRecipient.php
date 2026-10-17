<?php

namespace App\Enums;

/**
 * Who receives the ride PIN or live tracking on a third-party booking.
 */
enum ThirdPartyRecipient: string
{
    case Booker = 'booker';
    case Rider = 'rider';
    case Both = 'both';

    public function includesBooker(): bool
    {
        return $this !== self::Rider;
    }

    public function includesRider(): bool
    {
        return $this !== self::Booker;
    }
}
