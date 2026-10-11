<?php

namespace App\Enums;

enum EvStallStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Reserved = 'reserved';
    case OutOfService = 'out_of_service';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Occupied => 'Occupied',
            self::Reserved => 'Reserved',
            self::OutOfService => 'Out of Service',
        };
    }

    public function isAvailableForReservation(): bool
    {
        return $this === self::Available;
    }
}
