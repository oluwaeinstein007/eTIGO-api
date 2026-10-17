<?php

namespace App\Observers;

use App\Events\EvStationAvailabilityChanged;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Tells the admin console when a station's availability changes: a stall
 * changing status, a reservation joining or leaving the queue, or the
 * station itself being switched on, off or resized.
 */
class EvAvailabilityObserver
{
    public function saved(Model $model): void
    {
        $watched = match (true) {
            $model instanceof EvChargingStation => ['status', 'total_stalls'],
            $model instanceof EvChargingStall, $model instanceof EvReservation => ['status', 'queue_position'],
            default => [],
        };

        // New stalls are only ever added with a change to the station's stall
        // count, which reports them; skipping them avoids one event per stall.
        $created = $model->wasRecentlyCreated && ! $model instanceof EvChargingStall;

        if ($created || (! $model->wasRecentlyCreated && $model->wasChanged($watched))) {
            $this->notify($model);
        }
    }

    public function deleted(Model $model): void
    {
        $this->notify($model);
    }

    private function notify(Model $model): void
    {
        $stationId = $model instanceof EvChargingStation ? $model->getKey() : $model->getAttribute('station_id');

        if (! $stationId) {
            return;
        }

        // A dashboard refresh must never break a reservation or a stall update.
        try {
            EvStationAvailabilityChanged::dispatch((string) $stationId);
        } catch (\Throwable $e) {
            Log::warning('EV availability broadcast failed', ['station_id' => $stationId, 'error' => $e->getMessage()]);
        }
    }
}
