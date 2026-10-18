<?php

namespace App\Enums;

enum MaintenanceItemStatus: string
{
    case Ok = 'ok';
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';
    case Expired = 'expired';
    // No service date, or no approved paper with an expiry date, yet.
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Up to date',
            self::DueSoon => 'Due soon',
            self::Overdue => 'Overdue',
            self::Expired => 'Expired',
            self::Unknown => 'No record yet',
        };
    }

    public function needsAttention(): bool
    {
        return $this !== self::Ok;
    }
}
