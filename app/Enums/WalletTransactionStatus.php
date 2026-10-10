<?php

namespace App\Enums;

enum WalletTransactionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Abandoned = 'abandoned';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Abandoned => 'Abandoned',
            self::Failed => 'Failed',
        };
    }
}
