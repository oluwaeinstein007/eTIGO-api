<?php

namespace App\Services;

use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Models\EvChargingStation;
use App\Models\EvChargingStall;

class StallAvailabilityService
{
    public function getStationAvailability(EvChargingStation $station): array
    {
        $counts = $station->stalls()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'total' => $station->total_stalls,
            'available' => (int) ($counts[EvStallStatus::Available->value] ?? 0),
            'occupied' => (int) ($counts[EvStallStatus::Occupied->value] ?? 0),
            'reserved' => (int) ($counts[EvStallStatus::Reserved->value] ?? 0),
            'out_of_service' => (int) ($counts[EvStallStatus::OutOfService->value] ?? 0),
            'queue_length' => $station->reservations()
                ->where('status', EvReservationStatus::Queued)
                ->count(),
        ];
    }

    public function findAvailableStall(EvChargingStation $station): ?EvChargingStall
    {
        return $station->stalls()
            ->where('status', EvStallStatus::Available)
            ->orderBy('stall_number')
            ->lockForUpdate()
            ->first();
    }

    public function findImminentDepartureStall(EvChargingStation $station): ?EvChargingStall
    {
        $threshold = config('ev_charging.imminent_departure_minutes', 15);

        return $station->stalls()
            ->where('status', EvStallStatus::Occupied)
            ->whereNotNull('estimated_departure_at')
            ->where('estimated_departure_at', '<=', now()->addMinutes($threshold))
            ->where('estimated_departure_at', '>', now())
            ->orderBy('estimated_departure_at')
            ->first();
    }

    public function occupyStall(EvChargingStall $stall, string $driverId, ?int $estimatedMinutes = null): void
    {
        $stall->update([
            'status' => EvStallStatus::Occupied,
            'current_vehicle_driver_id' => $driverId,
            'occupied_since' => now(),
            'estimated_departure_at' => $estimatedMinutes ? now()->addMinutes($estimatedMinutes) : null,
            'updated_at' => now(),
        ]);
    }

    public function reserveStall(EvChargingStall $stall): void
    {
        $stall->update([
            'status' => EvStallStatus::Reserved,
            'updated_at' => now(),
        ]);
    }

    public function releaseStall(EvChargingStall $stall): void
    {
        $stall->update([
            'status' => EvStallStatus::Available,
            'current_vehicle_driver_id' => null,
            'occupied_since' => null,
            'estimated_departure_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function getEstimatedWaitMinutes(EvChargingStation $station): ?int
    {
        $soonestDeparture = $station->stalls()
            ->where('status', EvStallStatus::Occupied)
            ->whereNotNull('estimated_departure_at')
            ->where('estimated_departure_at', '>', now())
            ->orderBy('estimated_departure_at')
            ->value('estimated_departure_at');

        if (! $soonestDeparture) {
            return null;
        }

        return (int) now()->diffInMinutes($soonestDeparture, absolute: true);
    }
}
