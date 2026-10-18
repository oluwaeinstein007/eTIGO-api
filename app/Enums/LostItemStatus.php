<?php

namespace App\Enums;

enum LostItemStatus: string
{
    // Filed, but no driver could be told (support follows up).
    case Reported = 'reported';
    // The driver has been asked to check the car.
    case DriverNotified = 'driver_notified';
    // The driver has the item; passenger and driver arrange the return.
    case DriverConfirmed = 'driver_confirmed';
    // The driver did not find it. The passenger can ask support to help.
    case DriverDenied = 'driver_denied';
    // The driver says it was handed back; waiting for the passenger.
    case Returned = 'returned';
    // The passenger has the item back.
    case Resolved = 'resolved';
    // Closed without the item being returned.
    case Unresolved = 'unresolved';
    // The passenger withdrew the report.
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Reported => 'Reported',
            self::DriverNotified => 'Driver notified',
            self::DriverConfirmed => 'Driver has the item',
            self::DriverDenied => 'Driver did not find it',
            self::Returned => 'Returned, awaiting confirmation',
            self::Resolved => 'Resolved',
            self::Unresolved => 'Closed, not recovered',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Unresolved, self::Cancelled], true);
    }

    /**
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Resolved, self::Unresolved, self::Cancelled];
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => ! $status->isTerminal()));
    }
}
