<?php

namespace App\Services;

use App\Contracts\PushNotificationGateway;
use App\Enums\RideStatus;
use App\Models\Ride;

class RideNotificationService
{
    public function __construct(
        private PushNotificationGateway $pushGateway,
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

        $this->pushGateway->sendToUser($ride->passenger_id, [
            'title' => 'Driver Found!',
            'body' => "Your driver {$ride->driver?->first_name} is on the way.",
            'data' => ['type' => 'ride_matched', 'ride_id' => (string) $ride->id],
        ]);

        if ($ride->driver_id) {
            $this->pushGateway->sendToUser($ride->driver_id, [
                'title' => 'New Ride Request',
                'body' => "Pickup at {$ride->pickup_address}",
                'data' => ['type' => 'ride_assigned', 'ride_id' => (string) $ride->id],
            ]);
        }
    }

    private function notifyDriverEnRoute(Ride $ride): void
    {
        $this->pushGateway->sendToUser($ride->passenger_id, [
            'title' => 'Driver En Route',
            'body' => 'Your driver is on the way to pick you up.',
            'data' => ['type' => 'driver_en_route', 'ride_id' => (string) $ride->id],
        ]);
    }

    private function notifyDriverArrived(Ride $ride): void
    {
        $this->pushGateway->sendToUser($ride->passenger_id, [
            'title' => 'Driver Has Arrived',
            'body' => 'Your driver is waiting at the pickup location.',
            'data' => ['type' => 'driver_arrived', 'ride_id' => (string) $ride->id],
        ]);
    }

    private function notifyRideStarted(Ride $ride): void
    {
        $this->pushGateway->sendToUser($ride->passenger_id, [
            'title' => 'Ride Started',
            'body' => "Heading to {$ride->destination_address}",
            'data' => ['type' => 'ride_started', 'ride_id' => (string) $ride->id],
        ]);
    }

    private function notifyRideCompleted(Ride $ride): void
    {
        $currency = $ride->fare_currency ?? 'NGN';
        $fare = $ride->final_fare_amount ?? $ride->fare_estimate_amount;

        $this->pushGateway->sendToUser($ride->passenger_id, [
            'title' => 'Ride Completed',
            'body' => "Total fare: {$currency} ".number_format((float) $fare, 2),
            'data' => ['type' => 'ride_completed', 'ride_id' => (string) $ride->id],
        ]);

        if ($ride->driver_id) {
            $this->pushGateway->sendToUser($ride->driver_id, [
                'title' => 'Ride Completed',
                'body' => "Ride to {$ride->destination_address} completed.",
                'data' => ['type' => 'ride_completed', 'ride_id' => (string) $ride->id],
            ]);
        }
    }

    private function notifyCancellation(Ride $ride, RideStatus $previousStatus): void
    {
        $ride->loadMissing('cancelledByUser');
        $cancellerName = $ride->cancelledByUser?->first_name ?? 'The ride';

        if ($ride->cancelled_by !== $ride->passenger_id) {
            $this->pushGateway->sendToUser($ride->passenger_id, [
                'title' => 'Ride Cancelled',
                'body' => "{$cancellerName} cancelled the ride.".($ride->cancellation_reason ? " Reason: {$ride->cancellation_reason}" : ''),
                'data' => ['type' => 'ride_cancelled', 'ride_id' => (string) $ride->id],
            ]);
        }

        if ($ride->driver_id && $ride->cancelled_by !== $ride->driver_id) {
            $this->pushGateway->sendToUser($ride->driver_id, [
                'title' => 'Ride Cancelled',
                'body' => 'The passenger cancelled the ride.',
                'data' => ['type' => 'ride_cancelled', 'ride_id' => (string) $ride->id],
            ]);
        }
    }

    private function notifyNoDriverFound(Ride $ride): void
    {
        $this->pushGateway->sendToUser($ride->passenger_id, [
            'title' => 'No Driver Available',
            'body' => 'We couldn\'t find a driver nearby. Please try again shortly.',
            'data' => ['type' => 'no_driver_found', 'ride_id' => (string) $ride->id],
        ]);
    }
}
