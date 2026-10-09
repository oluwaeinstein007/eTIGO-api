<?php

namespace App\Enums;

enum PayoutStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Reversed => 'Reversed',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Reversed]);
    }
}
