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

    public function title(): string
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

    public function label(): string
    {
        return $this->title();
    }

    public function description(): string
    {
        return match ($this) {
            self::ChangedMind => 'I no longer need a ride or my travel plans have changed.',
            self::DriverTooFar => 'The assigned driver is located too far away from the pickup point.',
            self::WaitTooLong => 'The estimated pickup arrival time is longer than expected.',
            self::WrongPickup => 'The pickup location was selected or mapped incorrectly.',
            self::WrongDestination => 'The destination address was entered incorrectly.',
            self::PriceChanged => 'The ride fare or estimated cost is higher than expected.',
            self::FoundAlternative => 'I found another ride or alternative transportation.',
            self::Emergency => 'An unexpected personal emergency occurred.',
            self::DriverNoShow => 'The driver did not show up at the pickup location.',
            self::PassengerNoShow => 'The passenger was not present at the pickup location.',
            self::VehicleMismatch => 'The arriving vehicle or driver details do not match the app.',
            self::SafetyConcern => 'I feel unsafe or uncomfortable proceeding with this ride.',
            self::Other => 'Provide a custom reason if none of the options above apply.',
            self::SystemTimeout => 'The request timed out before a driver could accept the trip.',
        };
    }

    public function allowsCustomDescription(): bool
    {
        return $this === self::Other;
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

    /**
     * @return array{title: string, description: string, code: string, allows_custom_description: bool}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title(),
            'description' => $this->description(),
            'code' => $this->value,
            'allows_custom_description' => $this->allowsCustomDescription(),
        ];
    }

    /**
     * @return list<self>
     */
    public static function forPassenger(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $reason) => $reason->isPassengerReason(),
        ));
    }

    /**
     * @return list<self>
     */
    public static function forDriver(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $reason) => $reason->isDriverReason(),
        ));
    }

    /**
     * @return list<self>
     */
    public static function forRole(?string $role = null): array
    {
        return match ($role) {
            'driver' => self::forDriver(),
            'passenger' => self::forPassenger(),
            default => array_values(array_filter(
                self::cases(),
                fn (self $reason) => $reason !== self::SystemTimeout,
            )),
        };
    }
}
