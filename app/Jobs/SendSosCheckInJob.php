<?php

namespace App\Jobs;

use App\Contracts\PushNotificationGateway;
use App\Enums\SosIncidentStatus;
use App\Events\SosCheckInRequested;
use App\Models\SosIncident;
use App\Services\SosService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSosCheckInJob implements ShouldQueue
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

        if (! $incident || $incident->status !== SosIncidentStatus::Triggered) {
            return;
        }

        $sosService->sendCheckIn($incident);

        $escalationTimeout = config('sos.escalation_timeout_seconds', 30);

        try {
            $pushGateway->sendToUser($incident->triggered_by_user_id, [
                'title' => 'SOS Check-In',
                'body' => "Are you safe? Please respond within {$escalationTimeout} seconds.",
                'data' => [
                    'type' => 'sos_check_in',
                    'incident_id' => $incident->id,
                    'ride_id' => $incident->ride_id,
                    'timeout_seconds' => $escalationTimeout,
                ],
                'priority' => 'high',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send SOS check-in push notification', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            event(new SosCheckInRequested(
                incidentId: $incident->id,
                rideId: $incident->ride_id,
                userId: $incident->triggered_by_user_id,
                timeoutSeconds: $escalationTimeout,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to broadcast SOS check-in event', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }

        SosEscalationTimeoutJob::dispatch($incident->id)
            ->delay(now()->addSeconds($escalationTimeout));

        Log::info('SOS check-in sent', [
            'incident_id' => $incident->id,
            'escalation_timeout' => $escalationTimeout,
        ]);
    }
}
