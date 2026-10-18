<?php

namespace App\Enums;

/**
 * Where a dispute refund goes. Wallet credit is the default (OQ-27); a refund
 * back to the card is money movement beyond a wallet refund and always needs
 * Finance approval (OQ-21).
 */
enum DisputeRefundMethod: string
{
    case Wallet = 'wallet';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Wallet => 'Wallet credit',
            self::Card => 'Card refund',
        };
    }
}
