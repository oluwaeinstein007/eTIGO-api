<?php

namespace App\Enums;

enum RideStatus: string
{
    case Requested = 'requested';
    case Searching = 'searching';
    case Matched = 'matched';
    case DriverEnRoute = 'driver_en_route';
    case DriverArrived = 'driver_arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoDriverFound = 'no_driver_found';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Searching => 'Searching for Driver',
            self::Matched => 'Driver Matched',
            self::DriverEnRoute => 'Driver En Route',
            self::DriverArrived => 'Driver Arrived',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoDriverFound => 'No Driver Found',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, self::activeStatuses());
    }

    /**
     * @return list<self>
     */
    public static function activeStatuses(): array
    {
        return [
            self::Requested,
            self::Searching,
            self::Matched,
            self::DriverEnRoute,
            self::DriverArrived,
            self::InProgress,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Cancelled,
            self::NoDriverFound,
        ]);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [
            self::Requested,
            self::Searching,
            self::Matched,
            self::DriverEnRoute,
            self::DriverArrived,
        ]);
    }
}
