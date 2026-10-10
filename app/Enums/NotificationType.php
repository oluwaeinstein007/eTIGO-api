<?php

namespace App\Enums;

enum NotificationType: string
{
    // Ride events
    case RideMatched = 'ride_matched';
    case RideCancelled = 'ride_cancelled';
    case DriverArriving = 'driver_arriving';
    case RideStarted = 'ride_started';
    case RideCompleted = 'ride_completed';

    // SOS events
    case SosCheckIn = 'sos_check_in';
    case SosEscalated = 'sos_escalated';

    // Compliance events
    case ComplianceWarning = 'compliance_warning';

    // Promo events
    case PromoExpiring = 'promo_expiring';

    // Gamification events
    case TierUpgrade = 'tier_upgrade';

    // EV events
    case EvReservationReady = 'ev_reservation_ready';

    // Dispute events
    case DisputeUpdate = 'dispute_update';

    // KYC events
    case KycStatusChanged = 'kyc_status_changed';

    // Scheduled ride events
    case ScheduledRideReminder = 'scheduled_ride_reminder';

    // Lost & Found events
    case LostItemReport = 'lost_item_report';

    // Wallet events
    case TopupSuccess = 'topup_success';
    case TopupFailed = 'topup_failed';
    case RideWalletPayment = 'ride_wallet_payment';
    case WalletRefund = 'wallet_refund';
    case PayoutApproved = 'payout_approved';
    case PayoutPaid = 'payout_paid';
    case PayoutFailed = 'payout_failed';

    public function label(): string
    {
        return match ($this) {
            self::RideMatched => 'Ride Matched',
            self::RideCancelled => 'Ride Cancelled',
            self::DriverArriving => 'Driver Arriving',
            self::RideStarted => 'Ride Started',
            self::RideCompleted => 'Ride Completed',
            self::SosCheckIn => 'SOS Check-In',
            self::SosEscalated => 'SOS Escalated',
            self::ComplianceWarning => 'Compliance Warning',
            self::PromoExpiring => 'Promo Expiring',
            self::TierUpgrade => 'Tier Upgrade',
            self::EvReservationReady => 'EV Reservation Ready',
            self::DisputeUpdate => 'Dispute Update',
            self::KycStatusChanged => 'KYC Status Changed',
            self::ScheduledRideReminder => 'Scheduled Ride Reminder',
            self::LostItemReport => 'Lost Item Report',
            self::TopupSuccess => 'Top-up Successful',
            self::TopupFailed => 'Top-up Failed',
            self::RideWalletPayment => 'Wallet Ride Payment',
            self::WalletRefund => 'Wallet Refund',
            self::PayoutApproved => 'Payout Approved',
            self::PayoutPaid => 'Payout Paid',
            self::PayoutFailed => 'Payout Failed',
        };
    }

    public function channel(): string
    {
        return match ($this) {
            self::SosCheckIn, self::SosEscalated => 'push',
            default => 'push',
        };
    }
}
