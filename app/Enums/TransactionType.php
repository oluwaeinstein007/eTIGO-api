<?php

namespace App\Enums;

enum TransactionType: string
{
    case TopUp = 'top_up';
    case RidePayment = 'ride_payment';
    case RideSettlement = 'ride_settlement';
    case Commission = 'commission';
    case Tip = 'tip';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
    case Payout = 'payout';

    public function label(): string
    {
        return match ($this) {
            self::TopUp => 'Top Up',
            self::RidePayment => 'Ride Payment',
            self::RideSettlement => 'Ride Settlement',
            self::Commission => 'Commission',
            self::Tip => 'Tip',
            self::Refund => 'Refund',
            self::Adjustment => 'Adjustment',
            self::Payout => 'Payout',
        };
    }
}
