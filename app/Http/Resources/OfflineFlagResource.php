<?php

namespace App\Http\Resources;

use App\Models\OfflineTripFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OfflineTripFlag */
class OfflineFlagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'ride_id' => $this->ride_id,
            'driver_id' => $this->driver_id,
            'passenger_id' => $this->passenger_id,
            'status' => [
                'value' => $this->status()->value,
                'label' => $this->status()->label(),
            ],
            'sanction_tier' => [
                'value' => $this->sanction_tier->value,
                'label' => $this->sanction_tier->label(),
            ],
            'sanction_action' => $this->sanction_action,
            'is_disputed' => $this->is_disputed,
            'dispute_outcome' => $this->when($this->dispute_outcome, fn () => [
                'value' => $this->dispute_outcome->value,
                'label' => $this->dispute_outcome->label(),
            ]),
            'dispute_notes' => $this->when($this->is_disputed, $this->dispute_notes),
            'dispute' => $this->when($this->is_disputed, fn () => [
                'notes' => $this->dispute_notes,
                'filed_at' => $this->disputed_at?->toISOString(),
                'channel' => $this->dispute_channel,
            ]),
            'review_notes' => $this->when($isAdmin, fn () => $this->review_notes),
            'detection_summary' => $this->when($isAdmin, fn () => $this->detectionSummary()),
            'evidence' => $this->when($isAdmin, fn () => $this->evidence()),
            'detection_data' => $this->when($isAdmin, $this->detection_data),
            'flagged_at' => $this->flagged_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'driver' => new UserResource($this->whenLoaded('driver')),
            'driver_city' => $this->when(
                $isAdmin && $this->relationLoaded('driver') && $this->driver?->relationLoaded('driver'),
                fn () => $this->driver->driver?->relationLoaded('city') && $this->driver->driver->city
                    ? new CityResource($this->driver->driver->city)
                    : null,
            ),
            'passenger' => new UserResource($this->whenLoaded('passenger')),
            'ride' => new RideResource($this->whenLoaded('ride')),
            'resolved_by' => new UserResource($this->whenLoaded('resolvedBy')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
