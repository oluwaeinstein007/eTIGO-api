<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case RideUpdates = 'ride_updates';
    case Safety = 'safety';
    case Payments = 'payments';
    case Promotions = 'promotions';
    case Compliance = 'compliance';
    case Gamification = 'gamification';
    case EvCharging = 'ev_charging';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::RideUpdates => 'Ride Updates',
            self::Safety => 'Safety & SOS',
            self::Payments => 'Payments & Receipts',
            self::Promotions => 'Promotions & Offers',
            self::Compliance => 'Compliance Alerts',
            self::Gamification => 'Rewards & Tiers',
            self::EvCharging => 'EV Charging',
            self::Account => 'Account Updates',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RideUpdates => 'Driver matching, arrival, trip progress and completion',
            self::Safety => 'SOS check-ins, escalations and safety alerts',
            self::Payments => 'Payment confirmations, receipts and refunds',
            self::Promotions => 'Promo codes, discounts and special offers',
            self::Compliance => 'Offline trip flags, sanctions and compliance warnings',
            self::Gamification => 'Tier upgrades, carbon scores and rewards',
            self::EvCharging => 'EV reservation status and availability',
            self::Account => 'KYC status, profile changes and account alerts',
        };
    }

    public function isCritical(): bool
    {
        return match ($this) {
            self::Safety, self::Compliance => true,
            default => false,
        };
    }
}
