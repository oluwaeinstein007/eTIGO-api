<?php

namespace App\Enums;

enum RepairServiceType: string
{
    case Repair = 'repair';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Repair => 'Repair',
            self::Maintenance => 'Maintenance',
        };
    }
}
