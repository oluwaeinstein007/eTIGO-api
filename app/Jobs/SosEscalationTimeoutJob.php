<?php

namespace App\Jobs;

use App\Contracts\PushNotificationGateway;
use App\Enums\SosIncidentStatus;
use App\Models\SosIncident;
use App\Services\SosService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SosEscalationTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public readonly string $incidentId,
    ) {}

    public function handle(
        SosService $sosService,
        PushNotificationGateway $pushGateway,
    ): void {
        $incident = SosIncident::find($this->incidentId);

        if (! $incident || $incident->status !== SosIncidentStatus::CheckInSent) {
            return;
        }

        $sosService->escalate($incident);

        $incident->refresh();

        $ride = $incident->ride;
        $otherUserId = $incident->triggered_by_user_id === $ride?->passenger_id
            ? $ride->driver_id
            : $ride?->passenger_id;

        if ($otherUserId) {
            try {
                $pushGateway->sendToUser($otherUserId, [
                    'title' => 'Safety Alert',
                    'body' => 'An SOS has been escalated for your current ride. Our safety team has been notified.',
                    'data' => [
                        'type' => 'sos_escalated',
                        'incident_id' => $incident->id,
                        'ride_id' => $incident->ride_id,
                    ],
                    'priority' => 'high',
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to send SOS escalation notification to other party', [
                    'incident_id' => $incident->id,
                    'user_id' => $otherUserId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::critical('SOS incident escalated — check-in timeout', [
            'incident_id' => $incident->id,
            'ride_id' => $incident->ride_id,
        ]);
    }
}
