<?php

namespace App\Enums;

/**
 * Where a flag stands in compliance review, derived from the dispute and
 * review columns.
 */
enum OfflineFlagStatus: string
{
    case Flagged = 'flagged';
    case Disputed = 'disputed';
    case Upheld = 'upheld';
    case Overturned = 'overturned';

    public function label(): string
    {
        return match ($this) {
            self::Flagged => 'Flagged',
            self::Disputed => 'Disputed',
            self::Upheld => 'Upheld',
            self::Overturned => 'Overturned',
        };
    }
}
