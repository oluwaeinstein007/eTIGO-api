<?php

namespace App\Services;

use App\Enums\CancellationReason;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Models\Ride;

class RideNotificationService
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function notifyTransition(Ride $ride, RideStatus $from, RideStatus $to): void
    {
        match ($to) {
            RideStatus::Searching => $from === RideStatus::Scheduled ? $this->notifyScheduledRideDispatched($ride) : null,
            RideStatus::Matched => $this->notifyDriverMatched($ride),
            RideStatus::DriverEnRoute => $this->notifyDriverEnRoute($ride),
            RideStatus::DriverArrived => $this->notifyDriverArrived($ride),
            RideStatus::InProgress => $this->notifyRideStarted($ride),
            RideStatus::Completed => $this->notifyRideCompleted($ride),
            RideStatus::Cancelled => $this->notifyCancellation($ride, $from),
            RideStatus::NoDriverFound => $this->notifyNoDriverFound($ride),
            default => null,
        };
    }

    private function notifyScheduledRideDispatched(Ride $ride): void
    {
        // Given straight to the reserved driver: the matched push follows.
        if (Ride::whereKey($ride->id)->whereNotNull('driver_id')->exists()) {
            return;
        }

        $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::ScheduledRideDispatched,
            title: 'Finding Your Driver',
            body: "We're finding a driver for your scheduled ride from {$ride->pickup_address}.",
            data: ['ride_id' => (string) $ride->id],
        );
    }

    private function notifyDriverMatched(Ride $ride): void
    {
        $ride->loadMissing(['driver', 'passenger']);

        $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::RideMatched,
            title: 'Driver Found!',
            body: $ride->is_third_party
                ? "Your driver {$ride->driver?->first_name} is on the way to pick up {$ride->riderFirstName()}."
                : "Your driver {$ride->driver?->first_name} is on the way.",
            data: ['ride_id' => (string) $ride->id],
        );

        if ($ride->driver_id) {
            $this->notificationService->send(
                userId: $ride->driver_id,
                type: NotificationType::RideAssigned,
                title: 'Ride Assigned',
                body: "You have been assigned to a ride. Pickup at {$ride->pickup_address}",
                data: ['ride_id' => (string) $ride->id],
            );
        }
    }

    private function notifyDriverEnRoute(Ride $ride): void
    {
        $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::DriverEnRoute,
            title: 'Driver En Route',
            body: $ride->is_third_party
                ? "The driver is on the way to pick up {$ride->riderFirstName()}."
                : 'Your driver is on the way to pick you up.',
            data: ['ride_id' => (string) $ride->id],
        );
    }

    private function notifyDriverArrived(Ride $ride): void
    {
        $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::DriverArriving,
            title: 'Driver Has Arrived',
            body: $ride->is_third_party
                ? "The driver is waiting for {$ride->riderFirstName()} at the pickup location."
                : 'Your driver is waiting at the pickup location.',
            data: ['ride_id' => (string) $ride->id],
        );
    }

    private function notifyRideStarted(Ride $ride): void
    {
        $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::RideStarted,
            title: 'Ride Started',
            body: $ride->is_third_party
                ? "{$ride->riderFirstName()}'s trip has started. Heading to {$ride->destination_address}"
                : "Heading to {$ride->destination_address}",
            data: ['ride_id' => (string) $ride->id],
        );
    }

    private function notifyRideCompleted(Ride $ride): void
    {
        $currency = $ride->fare_currency ?? 'NGN';
        $fare = $ride->final_fare_amount ?? $ride->fare_estimate_amount;

        $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::RideCompleted,
            title: 'Ride Completed',
            body: "Total fare: {$currency} ".number_format((float) $fare, 2),
            data: ['ride_id' => (string) $ride->id],
        );

        if ($ride->driver_id) {
            $this->notificationService->send(
                userId: $ride->driver_id,
                type: NotificationType::RideCompleted,
                title: 'Ride Completed',
                body: "Ride to {$ride->destination_address} completed.",
                data: ['ride_id' => (string) $ride->id],
            );
        }
    }

    private function notifyCancellation(Ride $ride, RideStatus $previousStatus): void
    {
        $ride->loadMissing('cancelledByUser');
        $canceller = $ride->cancelledByUser;

        $cancellerLabel = match (true) {
            $canceller === null => 'E-tiGo',
            $canceller->isAdmin() => 'E-tiGo support',
            $canceller->id === $ride->driver_id => 'Your driver',
            default => 'The passenger',
        };

        $reasonLabel = $ride->cancellation_reason
            ? (CancellationReason::tryFrom($ride->cancellation_reason)?->label() ?? $ride->cancellation_reason)
            : null;

        $body = "{$cancellerLabel} cancelled the ride.".($reasonLabel ? " Reason: {$reasonLabel}." : '');

        // The scheduled-ride dispatcher and a city pause record a
        // passenger-facing explanation.
        $explained = [CancellationReason::ScheduledDispatchFailed->value, CancellationReason::CityPaused->value];
        if (in_array($ride->cancellation_reason, $explained, true) && $ride->cancellation_details) {
            $body = $ride->cancellation_details;
        }

        if ($ride->cancelled_by !== $ride->passenger_id) {
            $this->notificationService->send(
                userId: $ride->passenger_id,
                type: NotificationType::RideCancelled,
                title: 'Ride Cancelled',
                body: $body,
                data: ['ride_id' => (string) $ride->id],
            );
        }

        // A booking cancelled before dispatch has no driver yet, but a driver
        // may have reserved it.
        $ride->loadMissing('scheduledRide');
        $reservedDriverId = $ride->driver_id === null ? $ride->scheduledRide?->reserved_driver_id : null;

        if ($reservedDriverId !== null && $ride->cancelled_by !== $reservedDriverId) {
            $fee = (float) ($ride->scheduledRide->late_cancellation_fee ?? 0);
            $feeNote = $fee > 0 && $ride->scheduledRide->late_cancellation_fee_status !== 'waived'
                ? ' You will receive the late-cancellation fee once it is collected.'
                : '';

            $this->notificationService->send(
                userId: $reservedDriverId,
                type: NotificationType::RideCancelled,
                title: 'Reserved Pickup Cancelled',
                body: "The passenger's scheduled ride from {$ride->pickup_address} was cancelled.".$feeNote,
                data: ['ride_id' => (string) $ride->id],
            );
        }

        if ($ride->driver_id && $ride->cancelled_by !== $ride->driver_id) {
            $this->notificationService->send(
                userId: $ride->driver_id,
                type: NotificationType::RideCancelled,
                title: 'Ride Cancelled',
                body: $body,
                data: ['ride_id' => (string) $ride->id],
            );
        }
    }

    private function notifyNoDriverFound(Ride $ride): void
    {
        $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::NoDriverFound,
            title: 'No Driver Available',
            body: "We couldn't find a driver nearby. Please try again shortly.",
            data: ['ride_id' => (string) $ride->id],
        );
    }
}
