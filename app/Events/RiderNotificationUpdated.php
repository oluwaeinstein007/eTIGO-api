<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The delivery status of the rider's message on a third-party booking
 * changed. Sent to the booker only; when `booker_sees_pin` turns true, the
 * app fetches the ride again to show the PIN.
 */
class RiderNotificationUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $rideId,
        public string $bookerId,
        public string $status,
        public ?string $channel,
        public ?string $notifiedAt,
        public bool $bookerSeesPin,
        public bool $bookerSeesTracking,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->bookerId}")];
    }

    public function broadcastAs(): string
    {
        return 'ride.rider_notification.updated';
    }

    /**
     * @return array{ride_id: string, rider_notification_status: string, rider_notification_channel: string|null, rider_notified_at: string|null, booker_sees_pin: bool, booker_sees_tracking: bool}
     */
    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->rideId,
            'rider_notification_status' => $this->status,
            'rider_notification_channel' => $this->channel,
            'rider_notified_at' => $this->notifiedAt,
            'booker_sees_pin' => $this->bookerSeesPin,
            'booker_sees_tracking' => $this->bookerSeesTracking,
        ];
    }
}
