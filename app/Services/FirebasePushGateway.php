<?php

namespace App\Services;

use App\Contracts\PushNotificationGateway;
use App\Models\DeviceToken;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\Notification;

class FirebasePushGateway implements PushNotificationGateway
{
    public function __construct(
        private Messaging $messaging,
    ) {}

    /**
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $notification
     */
    public function sendToUser(int $userId, array $notification): void
    {
        $tokens = DeviceToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token')
            ->all();

        if (empty($tokens)) {
            return;
        }

        $this->sendToDevices($tokens, $notification);
    }

    /**
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     */
    public function sendToDevice(string $token, array $payload): void
    {
        $message = $this->buildMessage($payload)
            ->withChangedTarget('token', $token);

        $this->messaging->send($message);
    }

    /**
     * @param  array<string>  $tokens
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     * @return array{success_count: int, failure_count: int}
     */
    public function sendToDevices(array $tokens, array $payload): array
    {
        $message = $this->buildMessage($payload);
        $report = $this->messaging->sendMulticast($message, $tokens);

        $this->deactivateInvalidTokens($report);

        return [
            'success_count' => $report->successes()->count(),
            'failure_count' => $report->failures()->count(),
        ];
    }

    /**
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     */
    private function buildMessage(array $payload): CloudMessage
    {
        $message = CloudMessage::new()
            ->withNotification(Notification::create($payload['title'], $payload['body']));

        if (! empty($payload['data'])) {
            $stringData = array_map(strval(...), $payload['data']);
            $message = $message->withData($stringData);
        }

        return $message;
    }

    private function deactivateInvalidTokens(MulticastSendReport $report): void
    {
        foreach ($report->failures()->getItems() as $sendReport) {
            $target = $sendReport->target();

            if ($target && $target->type() === 'token') {
                DeviceToken::where('token', $target->value())
                    ->update(['is_active' => false]);
            }
        }
    }
}
