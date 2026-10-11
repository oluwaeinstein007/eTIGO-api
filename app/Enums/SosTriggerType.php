<?php

namespace App\Enums;

enum SosTriggerType: string
{
    case Passenger = 'passenger';
    case Driver = 'driver';

    public function label(): string
    {
        return match ($this) {
            self::Passenger => 'Passenger',
            self::Driver => 'Driver',
        };
    }
}
