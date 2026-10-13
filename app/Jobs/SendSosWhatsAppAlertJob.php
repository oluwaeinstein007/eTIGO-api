<?php

namespace App\Jobs;

use App\Models\SosIncident;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendSosWhatsAppAlertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        private readonly string $incidentId,
    ) {}

    public function handle(): void
    {
        $incident = SosIncident::with(['ride.passenger', 'ride.driver', 'triggeredBy'])->find($this->incidentId);

        if (! $incident) {
            return;
        }

        $safetyNumber = config('services.whatsapp.safety_number');
        if (! $safetyNumber) {
            Log::warning('SOS WhatsApp alert skipped — no safety number configured', [
                'incident_id' => $this->incidentId,
            ]);

            return;
        }

        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token');
        $templateName = config('services.whatsapp.sos_template_name', 'sos_emergency_alert');

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

        try {
            $response = Http::withToken($accessToken)
                ->connectTimeout(5)
                ->timeout(10)
                ->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($safetyNumber, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => ['code' => 'en'],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    ['type' => 'text', 'text' => $alertBody],
                                ],
                            ],
                        ],
                    ],
                ]);

            if ($response->failed()) {
                Log::error('SOS WhatsApp alert delivery failed', [
                    'incident_id' => $this->incidentId,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            } else {
                Log::info('SOS WhatsApp alert sent', ['incident_id' => $this->incidentId]);
            }
        } catch (\Throwable $e) {
            Log::error('SOS WhatsApp alert connection failed', [
                'incident_id' => $this->incidentId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
