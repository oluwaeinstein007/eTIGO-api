<?php

namespace App\Enums;

enum MaintenanceRecordStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
