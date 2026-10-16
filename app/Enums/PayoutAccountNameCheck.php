<?php

namespace App\Enums;

/**
 * What happens when a payout account's name does not match the driver's
 * verified name (wallet setting payout_account_name_check).
 */
enum PayoutAccountNameCheck: string
{
    case Off = 'off';
    case Review = 'review';
    case Block = 'block';
}
