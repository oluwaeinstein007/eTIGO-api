<?php

namespace App\Enums;

/**
 * Where an open dispute stands against its response targets. At risk once
 * less than a quarter of the time to the next target is left.
 */
enum DisputeSlaStatus: string
{
    case OnTrack = 'on_track';
    case AtRisk = 'at_risk';
    case Breached = 'breached';
    case Met = 'met';
    case Missed = 'missed';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::OnTrack => 'On track',
            self::AtRisk => 'Due soon',
            self::Breached => 'Overdue',
            self::Met => 'Resolved on time',
            self::Missed => 'Resolved late',
            self::None => 'No target',
        };
    }
}
