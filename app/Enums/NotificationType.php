<?php

namespace App\Enums;

enum NotificationType: string
{
    // Ride events
    case RideMatched = 'ride_matched';
    case RideCancelled = 'ride_cancelled';
    case DriverEnRoute = 'driver_en_route';
    case DriverArriving = 'driver_arriving';
    case RideStarted = 'ride_started';
    case RideCompleted = 'ride_completed';
    case NoDriverFound = 'no_driver_found';
    case RideAssigned = 'ride_assigned';

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
    case CashChangeCredit = 'cash_change_credit';

    public function label(): string
    {
        return match ($this) {
            self::RideMatched => 'Ride Matched',
            self::RideCancelled => 'Ride Cancelled',
            self::DriverEnRoute => 'Driver En Route',
            self::DriverArriving => 'Driver Arriving',
            self::RideStarted => 'Ride Started',
            self::RideCompleted => 'Ride Completed',
            self::NoDriverFound => 'No Driver Found',
            self::RideAssigned => 'Ride Assigned',
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
            self::CashChangeCredit => 'Cash Change Credited',
        };
    }

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::RideMatched,
            self::RideCancelled,
            self::DriverEnRoute,
            self::DriverArriving,
            self::RideStarted,
            self::RideCompleted,
            self::NoDriverFound,
            self::RideAssigned,
            self::ScheduledRideReminder => NotificationCategory::RideUpdates,

            self::SosCheckIn,
            self::SosEscalated => NotificationCategory::Safety,

            self::TopupSuccess,
            self::TopupFailed,
            self::RideWalletPayment,
            self::WalletRefund,
            self::PayoutApproved,
            self::PayoutPaid,
            self::PayoutFailed,
            self::CashChangeCredit => NotificationCategory::Payments,

            self::PromoExpiring => NotificationCategory::Promotions,

            self::ComplianceWarning => NotificationCategory::Compliance,

            self::TierUpgrade => NotificationCategory::Gamification,

            self::EvReservationReady => NotificationCategory::EvCharging,

            self::DisputeUpdate,
            self::KycStatusChanged,
            self::LostItemReport => NotificationCategory::Account,
        };
    }
}
