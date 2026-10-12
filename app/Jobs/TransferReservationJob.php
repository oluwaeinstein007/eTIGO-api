<?php

namespace App\Jobs;

use App\Contracts\PushNotificationGateway;
use App\Enums\NotificationType;
use App\Models\EvChargingStation;
use App\Services\ReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TransferReservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly string $stationId,
    ) {}

    public function handle(ReservationService $reservationService, PushNotificationGateway $pushGateway): void
    {
        $station = EvChargingStation::find($this->stationId);

        if (! $station) {
            Log::warning('TransferReservationJob: station not found', ['station_id' => $this->stationId]);

            return;
        }

        $reservation = $reservationService->transferToNextQueued($station);

        if ($reservation) {
            $pushGateway->sendToUser(
                $reservation->driver_id,
                [
                    'title' => 'EV Stall Ready',
                    'body' => "Stall {$reservation->stall?->stall_number} at {$station->name} is ready for you.",
                    'data' => [
                        'type' => NotificationType::EvReservationReady->value,
                        'reservation_id' => (string) $reservation->id,
                        'station_name' => $station->name,
                        'stall_number' => (string) ($reservation->stall?->stall_number ?? ''),
                    ],
                ],
            );

            Log::info('TransferReservationJob: driver notified', [
                'reservation_id' => $reservation->id,
                'driver_id' => $reservation->driver_id,
            ]);
        }
    }
}
