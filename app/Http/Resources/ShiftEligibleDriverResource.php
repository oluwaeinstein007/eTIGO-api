<?php

namespace App\Http\Resources;

use App\Models\Driver;
use App\Models\DriverShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A driver in the "book a shift" picker. The first fields match the driver
 * inside DriverShiftResource.
 *
 * @mixin Driver
 */
class ShiftEligibleDriverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;

        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'name' => trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')),
            'first_name' => $user?->first_name,
            'last_name' => $user?->last_name,
            'phone' => $user?->phone,
            'is_online' => (bool) $this->is_online,
            'city' => $this->city ? ['id' => $this->city->id, 'name' => $this->city->name] : null,
            'vehicle_ownership_type' => $this->vehicle_ownership_type?->value,
            // The shared car the driver holds now, if any (they keep it until
            // the next shift driver goes online with it).
            'shared_car' => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
            ] : null,
            'upcoming_shifts_count' => (int) $this->upcoming_shifts_count,
            // Only when the request has starts_at and ends_at: the driver's
            // shift at that time (booking them would be refused), or null.
            'clashing_shift' => $this->when($this->relationLoaded('shifts'), fn () => $this->clash($this->shifts->first())),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function clash(?DriverShift $shift): ?array
    {
        if ($shift === null) {
            return null;
        }

        return [
            'id' => $shift->id,
            'reference_number' => $shift->reference_number,
            'status' => $shift->status->value,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'vehicle' => $shift->vehicle ? ['id' => $shift->vehicle->id, 'plate_number' => $shift->vehicle->plate_number] : null,
        ];
    }
}
