<?php

namespace App\Enums;

enum EvReservationStatus: string
{
    case Reserved = 'reserved';
    case Queued = 'queued';
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reserved',
            self::Queued => 'Queued',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Expired, self::Cancelled]);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }
}
