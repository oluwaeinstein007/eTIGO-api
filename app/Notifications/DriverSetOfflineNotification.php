<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class DriverSetOfflineNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::DriverSetOffline->value,
            'title' => NotificationType::DriverSetOffline->label(),
            'body' => "We stopped receiving your location, so we've set you offline and you won't get ride requests. Open the app and go online again to keep receiving them.",
        ];
    }
}
