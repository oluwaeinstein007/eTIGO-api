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

    /**
     * Whether rides are checked for this condition yet. Shared journeys need
     * pooled rides, which arrive in Phase 2; until then the multiplier can be
     * configured but is never awarded.
     */
    public function isAwarded(): bool
    {
        return $this !== self::SharedJourney;
    }
}
