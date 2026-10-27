<?php

namespace App\Services;

use App\Contracts\PushNotificationGateway;
use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\SendReport;

class FirebasePushGateway implements PushNotificationGateway
{
    public function __construct(
        private Messaging $messaging,
    ) {}

    /**
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $notification
     */
    public function sendToUser(string $userId, array $notification): void
    {
        $tokens = DeviceToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token')
            ->all();

        if (empty($tokens)) {
            Log::info('Push skipped: user has no active device', [
                'user_id' => $userId,
                'type' => $notification['data']['type'] ?? null,
            ]);

            return;
        }

        $report = $this->multicast($tokens, $notification);

        if ($report->hasFailures()) {
            Log::warning('Push not delivered to every device', [
                'user_id' => $userId,
                'type' => $notification['data']['type'] ?? null,
                'success_count' => $report->successes()->count(),
                'failure_count' => $report->failures()->count(),
                // Error types only: Firebase's messages quote the full device token.
                'errors' => array_values(array_unique(array_map(
                    static fn (SendReport $sendReport): string => class_basename($sendReport->error() ?? 'unknown'),
                    $report->failures()->getItems(),
                ))),
            ]);
        }
    }

    /**
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     */
    public function sendToDevice(string $token, array $payload): void
    {
        $message = $this->buildMessage($payload)
            ->toToken($token);

        $this->messaging->send($message);
    }

    /**
     * @param  array<string>  $tokens
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     * @return array{success_count: int, failure_count: int}
     */
    public function sendToDevices(array $tokens, array $payload): array
    {
        $report = $this->multicast($tokens, $payload);

        return [
            'success_count' => $report->successes()->count(),
            'failure_count' => $report->failures()->count(),
        ];
    }

    /**
     * @param  array<string>  $tokens
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     */
    private function multicast(array $tokens, array $payload): MulticastSendReport
    {
        $report = $this->messaging->sendMulticast($this->buildMessage($payload), $tokens);

        $this->deactivateInvalidTokens($report);

        return $report;
    }

    /**
     * @param  array{title: string, body: string, data?: array<string, mixed>}  $payload
     */
    private function buildMessage(array $payload): CloudMessage
    {
        $message = CloudMessage::new()
            ->withNotification(Notification::create($payload['title'], $payload['body']))
            ->withAndroidConfig(AndroidConfig::fromArray([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'etigo_updates',
                    'sound' => 'default',
                ],
            ]))
            ->withApnsConfig(ApnsConfig::fromArray([
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'badge' => 1,
                    ],
                ],
            ]));

        if (! empty($payload['data'])) {
            $stringData = [];
            foreach ($payload['data'] as $key => $value) {
                $stringData[(string) $key] = match (true) {
                    is_array($value) => json_encode($value),
                    // (string) false is "", which apps can't parse as a bool.
                    is_bool($value) => $value ? 'true' : 'false',
                    default => (string) $value,
                };
            }
            $message = $message->withData($stringData);
        }

        return $message;
    }

    private function deactivateInvalidTokens(MulticastSendReport $report): void
    {
        foreach ($report->failures()->getItems() as $sendReport) {
            $target = $sendReport->target();

            if (
                $target &&
                $target->type() === 'token' &&
                ($sendReport->messageTargetWasInvalid() || $sendReport->messageWasSentToUnknownToken())
            ) {
                DeviceToken::where('token', $target->value())
                    ->update(['is_active' => false]);
            }
        }
    }
}
