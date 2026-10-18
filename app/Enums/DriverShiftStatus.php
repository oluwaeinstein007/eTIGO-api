<?php

namespace App\Enums;

/**
 * A shift on a shared fleet car (OQ-20):
 * scheduled → active (the driver went online in their window) → completed,
 * scheduled → missed (not online 30 min after the start; may still start late),
 * scheduled / missed → cancelled (operations).
 */
enum DriverShiftStatus: string
{
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Completed = 'completed';
    case Missed = 'missed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Active => 'In progress',
            self::Completed => 'Completed',
            self::Missed => 'Missed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Shifts that still hold their time slot on the car and the driver.
     *
     * @return list<self>
     */
    public static function holdingSlot(): array
    {
        return [self::Scheduled, self::Active, self::Missed, self::Completed];
    }

    /**
     * Shifts the driver can still go online for.
     *
     * @return list<self>
     */
    public static function workable(): array
    {
        return [self::Scheduled, self::Active, self::Missed];
    }
}
