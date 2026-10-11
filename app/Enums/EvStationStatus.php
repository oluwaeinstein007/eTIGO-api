<?php

namespace App\Enums;

enum EvStationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Maintenance => 'Under Maintenance',
        };
    }

    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
