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
    case DriverLocationMissing = 'driver_location_missing';
    case DriverSetOffline = 'driver_set_offline';

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
    case EvReservationCancelled = 'ev_reservation_cancelled';
    case EvChargeRequestConfirmed = 'ev_charge_request_confirmed';
    case EvChargeRequestDeclined = 'ev_charge_request_declined';
    case EvReservationEnded = 'ev_reservation_ended';
    case FleetAgreementPaused = 'fleet_agreement_paused';
    case FleetAgreementResumed = 'fleet_agreement_resumed';
    case VehicleFuelTypeRequired = 'vehicle_fuel_type_required';

    // Dispute events
    case DisputeUpdate = 'dispute_update';

    // KYC events
    case KycStatusChanged = 'kyc_status_changed';

    // Scheduled ride events
    case ScheduledRideReminder = 'scheduled_ride_reminder';
    case ScheduledRideDispatched = 'scheduled_ride_dispatched';
    case ScheduledRideReserved = 'scheduled_ride_reserved';
    case ScheduledRideReservationReleased = 'scheduled_ride_reservation_released';

    // Third-party booking events
    case ThirdPartyRiderUnreachable = 'third_party_rider_unreachable';

    // Lost & Found events
    case LostItemReport = 'lost_item_report';
    case LostItemUpdate = 'lost_item_update';
    case LostItemMessage = 'lost_item_message';

    // Driver scheduling (shared fleet cars)
    case DriverShiftAssigned = 'driver_shift_assigned';
    case DriverShiftUpdated = 'driver_shift_updated';
    case DriverShiftCancelled = 'driver_shift_cancelled';
    case DriverShiftMissed = 'driver_shift_missed';
    case DriverShiftEnding = 'driver_shift_ending';
    case DriverShiftEnded = 'driver_shift_ended';
    case ShiftHandoverDelayed = 'shift_handover_delayed';

    // Vehicle maintenance
    case VehicleMaintenanceDue = 'vehicle_maintenance_due';
    case VehicleBlocked = 'vehicle_blocked';
    case VehicleUnblocked = 'vehicle_unblocked';
    case MaintenanceRecordReviewed = 'maintenance_record_reviewed';

    // Repair and service requests
    case RepairRequestScheduled = 'repair_request_scheduled';
    case RepairRequestCompleted = 'repair_request_completed';
    case RepairRequestCancelled = 'repair_request_cancelled';

    // City events (multi-city)
    case CityLaunched = 'city_launched';
    case CityStatusChanged = 'city_status_changed';
    case DriverCityChanged = 'driver_city_changed';

    // Wallet events
    case TopupSuccess = 'topup_success';
    case TopupFailed = 'topup_failed';
    case RideWalletPayment = 'ride_wallet_payment';
    case WalletRefund = 'wallet_refund';
    case PayoutApproved = 'payout_approved';
    case PayoutPaid = 'payout_paid';
    case PayoutFailed = 'payout_failed';
    case PayoutRejected = 'payout_rejected';
    case CashChangeCredit = 'cash_change_credit';
    case PayoutAccountChanged = 'payout_account_changed';
    case PayoutAccountReviewed = 'payout_account_reviewed';
    case BalanceDue = 'balance_due';

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
            self::DriverLocationMissing => 'We Can\'t See Your Location',
            self::DriverSetOffline => 'You Are Offline',
            self::SosCheckIn => 'SOS Check-In',
            self::SosEscalated => 'SOS Escalated',
            self::ComplianceWarning => 'Compliance Warning',
            self::PromoExpiring => 'Promo Expiring',
            self::TierUpgrade => 'Tier Upgrade',
            self::EvReservationReady => 'EV Reservation Ready',
            self::EvReservationCancelled => 'EV Reservation Cancelled',
            self::EvChargeRequestConfirmed => 'Charging Request Confirmed',
            self::EvChargeRequestDeclined => 'Charging Request Declined',
            self::EvReservationEnded => 'Charging Session Ended',
            self::FleetAgreementPaused => 'Fleet Agreement Paused',
            self::FleetAgreementResumed => 'Fleet Agreement Resumed',
            self::VehicleFuelTypeRequired => 'Add Your Vehicle\'s Fuel Type',
            self::DisputeUpdate => 'Dispute Update',
            self::KycStatusChanged => 'KYC Status Changed',
            self::ScheduledRideReminder => 'Scheduled Ride Reminder',
            self::ScheduledRideDispatched => 'Finding Your Driver',
            self::ScheduledRideReserved => 'Driver Reserved',
            self::ScheduledRideReservationReleased => 'Reservation Released',
            self::ThirdPartyRiderUnreachable => 'Rider Not Reached',
            self::LostItemReport => 'Lost Item Report',
            self::LostItemUpdate => 'Lost Item Update',
            self::LostItemMessage => 'Lost Item Message',
            self::DriverShiftAssigned => 'New Shift',
            self::DriverShiftUpdated => 'Shift Changed',
            self::DriverShiftCancelled => 'Shift Cancelled',
            self::DriverShiftMissed => 'Shift Missed',
            self::DriverShiftEnding => 'Shift Ending',
            self::DriverShiftEnded => 'Shift Ended',
            self::ShiftHandoverDelayed => 'Car Running Late',
            self::VehicleMaintenanceDue => 'Vehicle Check Due',
            self::VehicleBlocked => 'You Can\'t Go Online',
            self::VehicleUnblocked => 'You Can Go Online Again',
            self::MaintenanceRecordReviewed => 'Maintenance Record Checked',
            self::RepairRequestScheduled => 'Workshop Visit Booked',
            self::RepairRequestCompleted => 'Vehicle Service Complete',
            self::RepairRequestCancelled => 'Service Request Cancelled',
            self::CityLaunched => 'Now In Your City',
            self::CityStatusChanged => 'City Update',
            self::DriverCityChanged => 'Your City Changed',
            self::TopupSuccess => 'Top-up Successful',
            self::TopupFailed => 'Top-up Failed',
            self::RideWalletPayment => 'Wallet Ride Payment',
            self::WalletRefund => 'Wallet Refund',
            self::PayoutApproved => 'Payout Approved',
            self::PayoutPaid => 'Payout Paid',
            self::PayoutFailed => 'Payout Failed',
            self::PayoutRejected => 'Payout Rejected',
            self::CashChangeCredit => 'Cash Change Credited',
            self::PayoutAccountChanged => 'Payout Account Changed',
            self::PayoutAccountReviewed => 'Payout Account Checked',
            self::BalanceDue => 'Balance Due',
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
            self::DriverLocationMissing,
            self::DriverSetOffline,
            self::ScheduledRideReminder,
            self::ScheduledRideDispatched,
            self::ScheduledRideReserved,
            self::ScheduledRideReservationReleased,
            self::ThirdPartyRiderUnreachable => NotificationCategory::RideUpdates,

            self::SosCheckIn,
            self::SosEscalated => NotificationCategory::Safety,

            self::TopupSuccess,
            self::TopupFailed,
            self::RideWalletPayment,
            self::WalletRefund,
            self::PayoutApproved,
            self::PayoutPaid,
            self::PayoutFailed,
            self::PayoutRejected,
            self::CashChangeCredit,
            self::PayoutAccountChanged,
            self::PayoutAccountReviewed,
            self::BalanceDue => NotificationCategory::Payments,

            self::PromoExpiring => NotificationCategory::Promotions,

            self::ComplianceWarning,
            self::VehicleMaintenanceDue,
            self::VehicleBlocked,
            self::VehicleUnblocked,
            self::MaintenanceRecordReviewed => NotificationCategory::Compliance,

            self::TierUpgrade => NotificationCategory::Gamification,

            self::EvReservationReady,
            self::EvReservationCancelled,
            self::EvChargeRequestConfirmed,
            self::EvChargeRequestDeclined,
            self::EvReservationEnded => NotificationCategory::EvCharging,

            self::DisputeUpdate,
            self::KycStatusChanged,
            self::VehicleFuelTypeRequired,
            self::LostItemReport,
            self::LostItemUpdate,
            self::LostItemMessage,
            self::CityLaunched,
            self::CityStatusChanged,
            self::DriverCityChanged,
            self::DriverShiftAssigned,
            self::DriverShiftUpdated,
            self::DriverShiftCancelled,
            self::DriverShiftMissed,
            self::DriverShiftEnding,
            self::DriverShiftEnded,
            self::ShiftHandoverDelayed,
            self::FleetAgreementPaused,
            self::FleetAgreementResumed,
            self::RepairRequestScheduled,
            self::RepairRequestCompleted,
            self::RepairRequestCancelled => NotificationCategory::Account,
        };
    }
}
