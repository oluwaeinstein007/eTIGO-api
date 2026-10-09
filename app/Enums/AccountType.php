<?php

namespace App\Enums;

enum AccountType: string
{
    case PassengerWallet = 'passenger_wallet';
    case DriverEarningsPending = 'driver_earnings_pending';
    case DriverEarningsAvailable = 'driver_earnings_available';
    case PlatformCommission = 'platform_commission';
    case PspClearing = 'psp_clearing';
    case Refunds = 'refunds';

    public function label(): string
    {
        return match ($this) {
            self::PassengerWallet => 'Passenger Wallet',
            self::DriverEarningsPending => 'Driver Earnings (Pending)',
            self::DriverEarningsAvailable => 'Driver Earnings (Available)',
            self::PlatformCommission => 'Platform Commission',
            self::PspClearing => 'PSP Clearing',
            self::Refunds => 'Refunds',
        };
    }

    public function isSystemAccount(): bool
    {
        return in_array($this, [self::PlatformCommission, self::PspClearing, self::Refunds]);
    }

    public function isDriverAccount(): bool
    {
        return in_array($this, [self::DriverEarningsPending, self::DriverEarningsAvailable]);
    }
}
