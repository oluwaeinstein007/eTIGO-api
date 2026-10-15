<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationType;
use App\Services\NotificationService;
use Illuminate\Notifications\Notification;

/**
 * Delivers a Laravel notification through the app's own notification
 * pipeline: an in-app record (with the title and body the app's notifications
 * table requires) plus a push, honouring the user's preferences.
 *
 * The notification's toArray() must provide `type` (a NotificationType value),
 * `title` and `body`; any other keys are sent along as the payload data.
 */
class AppNotificationChannel
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $payload = $notification->toArray($notifiable);

        $this->notificationService->send(
            userId: (string) $notifiable->getKey(),
            type: NotificationType::from($payload['type']),
            title: $payload['title'],
            body: $payload['body'],
            data: array_diff_key($payload, array_flip(['type', 'title', 'body'])),
        );
    }
}
