<?php

namespace App\Services;

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

    private function notifyDriverMatched(Ride $ride): void
    {
        $ride->loadMissing(['driver', 'passenger']);

        $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::RideMatched,
            title: 'Driver Found!',
            body: "Your driver {$ride->driver?->first_name} is on the way.",
            data: ['ride_id' => (string) $ride->id],
        );

        if ($ride->driver_id) {
            $this->notificationService->send(
                userId: $ride->driver_id,
                type: NotificationType::RideAssigned,
                title: 'New Ride Request',
                body: "Pickup at {$ride->pickup_address}",
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
            body: 'Your driver is on the way to pick you up.',
            data: ['ride_id' => (string) $ride->id],
        );
    }

    private function notifyDriverArrived(Ride $ride): void
    {
        $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::DriverArriving,
            title: 'Driver Has Arrived',
            body: 'Your driver is waiting at the pickup location.',
            data: ['ride_id' => (string) $ride->id],
        );
    }

    private function notifyRideStarted(Ride $ride): void
    {
        $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::RideStarted,
            title: 'Ride Started',
            body: "Heading to {$ride->destination_address}",
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
        $cancellerName = $ride->cancelledByUser?->first_name ?? 'The ride';

        if ($ride->cancelled_by !== $ride->passenger_id) {
            $body = "{$cancellerName} cancelled the ride.";
            if ($ride->cancellation_reason) {
                $body .= " Reason: {$ride->cancellation_reason}";
            }

            $this->notificationService->send(
                userId: $ride->passenger_id,
                type: NotificationType::RideCancelled,
                title: 'Ride Cancelled',
                body: $body,
                data: ['ride_id' => (string) $ride->id],
            );
        }

        if ($ride->driver_id && $ride->cancelled_by !== $ride->driver_id) {
            $this->notificationService->send(
                userId: $ride->driver_id,
                type: NotificationType::RideCancelled,
                title: 'Ride Cancelled',
                body: 'The passenger cancelled the ride.',
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
