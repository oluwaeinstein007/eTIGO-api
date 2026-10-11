<?php

namespace App\Enums;

enum DisputeOutcome: string
{
    case Pending = 'pending';
    case Upheld = 'upheld';
    case Overturned = 'overturned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Upheld => 'Upheld',
            self::Overturned => 'Overturned',
        };
    }
}
