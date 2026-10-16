<?php

namespace App\Enums;

enum BalanceDueReason: string
{
    case LateCancellationFee = 'late_cancellation_fee';
    case WalletShortfall = 'wallet_shortfall';

    public function label(): string
    {
        return match ($this) {
            self::LateCancellationFee => 'Late cancellation fee',
            self::WalletShortfall => 'Unpaid ride fare',
        };
    }
}
