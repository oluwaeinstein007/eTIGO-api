<?php

namespace App\Enums;

enum CancellationReason: string
{
    case ChangedMind = 'changed_mind';
    case DriverTooFar = 'driver_too_far';
    case WaitTooLong = 'wait_too_long';
    case WrongPickup = 'wrong_pickup';
    case WrongDestination = 'wrong_destination';
    case PriceChanged = 'price_changed';
    case FoundAlternative = 'found_alternative';
    case Emergency = 'emergency';
    case DriverNoShow = 'driver_no_show';
    case PassengerNoShow = 'passenger_no_show';
    case VehicleMismatch = 'vehicle_mismatch';
    case SafetyConcern = 'safety_concern';
    case Other = 'other';
    case SystemTimeout = 'system_timeout';

    public function label(): string
    {
        return match ($this) {
            self::ChangedMind => 'Changed my mind',
            self::DriverTooFar => 'Driver is too far',
            self::WaitTooLong => 'Wait time too long',
            self::WrongPickup => 'Wrong pickup location',
            self::WrongDestination => 'Wrong destination',
            self::PriceChanged => 'Price changed',
            self::FoundAlternative => 'Found alternative transport',
            self::Emergency => 'Emergency',
            self::DriverNoShow => 'Driver did not show up',
            self::PassengerNoShow => 'Passenger did not show up',
            self::VehicleMismatch => 'Vehicle does not match',
            self::SafetyConcern => 'Safety concern',
            self::Other => 'Other',
            self::SystemTimeout => 'System timeout',
        };
    }

    public function isPassengerReason(): bool
    {
        return in_array($this, [
            self::ChangedMind,
            self::DriverTooFar,
            self::WaitTooLong,
            self::WrongPickup,
            self::WrongDestination,
            self::PriceChanged,
            self::FoundAlternative,
            self::Emergency,
            self::DriverNoShow,
            self::VehicleMismatch,
            self::SafetyConcern,
            self::Other,
        ]);
    }

    public function isDriverReason(): bool
    {
        return in_array($this, [
            self::PassengerNoShow,
            self::SafetyConcern,
            self::Emergency,
            self::Other,
        ]);
    }
}
