<?php

namespace App\Services;

use App\Contracts\PushNotificationGateway;
use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        private PushNotificationGateway $pushGateway,
    ) {}

    /**
     * Send a notification through all enabled channels (in-app + push).
     *
     * @param  array<string, mixed>  $data
     */
    public function send(
        string $userId,
        NotificationType $type,
        string $title,
        string $body,
        array $data = [],
    ): ?Notification {
        $category = $type->category();
        $preferences = $this->getPreferences($userId, $category);

        $notification = null;

        if ($preferences['in_app_enabled']) {
            $notification = $this->persist($userId, $type, $title, $body, $data);
        }

        if ($preferences['push_enabled'] && config('notification.channels.push', true)) {
            $this->sendPush($userId, $type, $title, $body, $data);
        }

        return $notification;
    }

    /**
     * Send a notification that bypasses user preferences (safety-critical).
     *
     * @param  array<string, mixed>  $data
     */
    public function sendCritical(
        string $userId,
        NotificationType $type,
        string $title,
        string $body,
        array $data = [],
    ): Notification {
        $notification = $this->persist($userId, $type, $title, $body, $data);

        if (config('notification.channels.push', true)) {
            $this->sendPush($userId, $type, $title, $body, $data);
        }

        return $notification;
    }

    /**
     * Send push-only notification (no DB persistence).
     * Used for transient updates like location or ETA changes.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendPushOnly(
        string $userId,
        string $title,
        string $body,
        array $data = [],
    ): void {
        if (! config('notification.channels.push', true)) {
            return;
        }

        try {
            $this->pushGateway->sendToUser($userId, [
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Push notification failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a notification to multiple users.
     *
     * @param  array<string>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function sendToMany(
        array $userIds,
        NotificationType $type,
        string $title,
        string $body,
        array $data = [],
    ): void {
        foreach ($userIds as $userId) {
            $this->send($userId, $type, $title, $body, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(
        string $userId,
        NotificationType $type,
        string $title,
        string $body,
        array $data,
    ): Notification {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type->value,
            'title' => $title,
            'body' => $body,
            'data' => ! empty($data) ? $data : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendPush(
        string $userId,
        NotificationType $type,
        string $title,
        string $body,
        array $data,
    ): void {
        try {
            $pushData = array_merge($data, ['type' => $type->value]);

            $this->pushGateway->sendToUser($userId, [
                'title' => $title,
                'body' => $body,
                'data' => $pushData,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Push notification failed', [
                'user_id' => $userId,
                'type' => $type->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{push_enabled: bool, in_app_enabled: bool}
     */
    private function getPreferences(string $userId, NotificationCategory $category): array
    {
        if ($category->isCritical()) {
            return ['push_enabled' => true, 'in_app_enabled' => true];
        }

        $preference = NotificationPreference::where('user_id', $userId)
            ->where('category', $category->value)
            ->first();

        if (! $preference) {
            $default = config("notification.category_defaults.{$category->value}", true);

            return ['push_enabled' => $default, 'in_app_enabled' => $default];
        }

        return [
            'push_enabled' => $preference->push_enabled,
            'in_app_enabled' => $preference->in_app_enabled,
        ];
    }
}
