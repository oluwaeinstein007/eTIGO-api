<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Settled = 'settled';
    case Refunded = 'refunded';
    case Failed = 'failed';
    case PendingCollection = 'pending_collection';
    case Collected = 'collected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Authorized => 'Authorized',
            self::Captured => 'Captured',
            self::Settled => 'Settled',
            self::Refunded => 'Refunded',
            self::Failed => 'Failed',
            self::PendingCollection => 'Pending Collection',
            self::Collected => 'Collected',
        };
    }
}
