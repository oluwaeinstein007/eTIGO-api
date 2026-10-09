<?php

namespace App\Enums;

enum MultiplierConditionType: string
{
    case EvRide = 'ev_ride';
    case SharedJourney = 'shared_journey';
    case OffPeak = 'off_peak';

    public function label(): string
    {
        return match ($this) {
            self::EvRide => 'EV Ride',
            self::SharedJourney => 'Shared Journey',
            self::OffPeak => 'Off-Peak',
        };
    }
}
