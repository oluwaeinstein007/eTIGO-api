<?php

namespace App\Enums;

enum EvReservationStatus: string
{
    case PendingReview = 'pending_review';
    // A charge request operations accepted for a slot; the stall is held at that time.
    case Confirmed = 'confirmed';
    case Reserved = 'reserved';
    case Queued = 'queued';
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending Review',
            self::Confirmed => 'Confirmed',
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
