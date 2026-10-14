<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Models\SosIncident;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendSosWhatsAppAlertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        private readonly string $incidentId,
    ) {}

    public function handle(SmsGateway $gateway): void
    {
        $incident = SosIncident::with(['ride.passenger', 'ride.driver', 'triggeredBy'])->find($this->incidentId);

        if (! $incident) {
            return;
        }

        $safetyNumber = config('services.termii.safety_number');
        if (! $safetyNumber) {
            Log::warning('SOS alert skipped — no safety number configured', [
                'incident_id' => $this->incidentId,
            ]);

            return;
        }

        $ride = $incident->ride;
        $triggeredBy = $incident->triggeredBy;

        $alertBody = implode("\n", [
            "SOS ALERT — Incident #{$incident->id}",
            "Triggered by: {$triggeredBy?->full_name} ({$incident->trigger_type->value})",
            "Phone: {$triggeredBy?->phone}",
            "Ride: #{$ride?->id}",
            "GPS: {$incident->gps_lat}, {$incident->gps_lng}",
            'Maps: https://maps.google.com/?q='.$incident->gps_lat.','.$incident->gps_lng,
            'Time: '.now()->toDateTimeString(),
        ]);

        $sent = $gateway->send($safetyNumber, $alertBody);

        if ($sent) {
            Log::info('SOS alert sent', ['incident_id' => $this->incidentId]);
        } else {
            Log::error('SOS alert delivery failed on all channels', [
                'incident_id' => $this->incidentId,
            ]);

            $this->fail(new \RuntimeException("SOS alert delivery failed for incident {$this->incidentId}"));
        }
    }
}
