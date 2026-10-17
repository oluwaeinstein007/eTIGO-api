<?php

namespace App\Enums;

enum PromoStatus: string
{
    case Active = 'active';
    case Scheduled = 'scheduled';
    case Paused = 'paused';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Scheduled => 'Scheduled',
            self::Paused => 'Paused',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }
}
