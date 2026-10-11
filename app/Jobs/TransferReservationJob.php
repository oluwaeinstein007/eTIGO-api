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

        $reservationService->transferToNextQueued($station);
    }
}
