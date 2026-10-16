<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class DriverLocationMissingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly bool $onTrip,
        public readonly int $offlineAfterMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::DriverLocationMissing->value,
            'title' => NotificationType::DriverLocationMissing->label(),
            'body' => $this->onTrip
                ? "Your app has stopped sharing your location, so your passenger can't see where you are. Open the app to continue the trip."
                : "Your app has stopped sharing your location, so you won't get ride requests. Open the app to keep receiving them, or you'll be set offline after {$this->offlineAfterMinutes} minutes.",
            'on_trip' => $this->onTrip,
        ];
    }
}
