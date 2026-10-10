<?php

namespace App\Enums;

enum HoldStatus: string
{
    case Active = 'active';
    case Captured = 'captured';
    case Released = 'released';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Captured => 'Captured',
            self::Released => 'Released',
            self::Expired => 'Expired',
        };
    }
}
