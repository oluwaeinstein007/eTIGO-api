<?php

namespace App\Http\Resources;

use App\Models\EvReservation;
use App\Services\EvReservationFeeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EvReservation */
class EvReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vehicle = $this->driver?->driver?->vehicle;

        return [
            'id' => $this->id,
            'type' => $this->type ?? 'charging',
            'station_id' => $this->station_id,
            'stall_id' => $this->stall_id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'battery_level' => $this->battery_level,
            'requested_date' => $this->requested_date?->format('Y-m-d'),
            'requested_time' => $this->requested_time,
            'notes' => $this->notes,
            'approver_name' => $this->approver_name,
            'confirmed_slot' => $this->confirmed_slot,
            'check_in_time' => $this->check_in_time,
            // Set when operations confirm a charge request: the stall is held from then.
            'confirmed_slot_at' => $this->confirmed_slot_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'decline_reason' => $this->decline_reason,
            'reviewed_by' => $this->when(
                (bool) $request->user()?->isAdmin() && $this->reviewed_by_admin_id !== null,
                fn () => $this->reviewedBy ? [
                    'id' => $this->reviewedBy->id,
                    'name' => trim("{$this->reviewedBy->first_name} {$this->reviewedBy->last_name}"),
                ] : null,
            ),
            'queue_position' => $this->queue_position,
            'queue_ahead_count' => $this->queue_ahead_count,
            'queue_ahead_description' => $this->queue_ahead_description,
            'estimated_available_at' => $this->estimated_available_at?->toISOString(),
            'fee_amount' => $this->fee_amount,
            'fee_waived' => $this->fee_waived,
            'fee_charged_at' => $this->fee_charged_at?->toISOString(),
            'fee_refunded_at' => $this->fee_refunded_at?->toISOString(),
            // Cancelling before this time refunds the fee (null: nothing to refund).
            'free_cancellation_until' => $this->status->isTerminal()
                ? null
                : EvReservationFeeService::freeCancellationUntil($this->resource)?->toISOString(),
            'reserved_at' => $this->reserved_at?->toISOString(),
            'activated_at' => $this->activated_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'vehicle_name' => $vehicle ? "{$vehicle->make} {$vehicle->model}" : null,
            'vehicle_plate' => $vehicle?->plate_number,
            'vehicle_color' => $vehicle?->colour,
            'station_name' => $this->station?->name,
            'station_address' => $this->station?->address,
            'assigned_bay' => $this->stall ? "Bay {$this->stall->stall_number} · DC fast charger" : null,
            'station' => new EvStationResource($this->whenLoaded('station')),
            'stall' => new EvStallResource($this->whenLoaded('stall')),
            'driver' => new UserResource($this->whenLoaded('driver')),
            'submitted_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString() ?? $this->created_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
