<?php

namespace App\Enums;

enum BalanceDueReason: string
{
    case LateCancellationFee = 'late_cancellation_fee';
    case WalletShortfall = 'wallet_shortfall';
    case LostItemReturnFee = 'lost_item_return_fee';

    public function label(): string
    {
        return match ($this) {
            self::LateCancellationFee => 'Late cancellation fee',
            self::WalletShortfall => 'Unpaid ride fare',
            self::LostItemReturnFee => 'Lost item return fee',
        };
    }
}
