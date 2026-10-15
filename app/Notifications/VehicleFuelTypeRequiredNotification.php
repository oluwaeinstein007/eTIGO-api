<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class VehicleFuelTypeRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::VehicleFuelTypeRequired->value,
            'title' => NotificationType::VehicleFuelTypeRequired->label(),
            'body' => "Tell us whether your vehicle runs on petrol, diesel, CNG, hybrid or electric. Electric and plug-in hybrid drivers need this to use E-tiGo's charging stations.",
        ];
    }
}
