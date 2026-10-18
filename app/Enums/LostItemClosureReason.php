<?php

namespace App\Enums;

/**
 * Why a lost item report was closed without the item being returned.
 */
enum LostItemClosureReason: string
{
    case NotFound = 'not_found';
    case Unclaimed = 'unclaimed';
    case PassengerUnreachable = 'passenger_unreachable';
    case DriverUnreachable = 'driver_unreachable';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NotFound => 'Item not found',
            self::Unclaimed => 'Item not collected',
            self::PassengerUnreachable => 'Passenger could not be reached',
            self::DriverUnreachable => 'Driver could not be reached',
            self::Other => 'Other',
        };
    }
}
