<?php

namespace App\Http\Resources;

use App\Enums\EvReservationStatus;
use App\Models\EvReservation;
use App\Services\EvReservationFeeService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EvReservation */
class EvReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vehicle = $this->driver?->driver?->vehicle;
        $isAdmin = (bool) $request->user()?->isAdmin();
        $ahead = $this->status === EvReservationStatus::Queued && $this->queue_position !== null
            ? max(0, $this->queue_position - 1)
            : null;

        return [
            'id' => $this->id,
            'type' => $this->type ?? 'charging',
            // request: reviewed by operations; instant: reserved from the station list.
            'kind' => $this->resource->kind(),
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
                $isAdmin && $this->reviewed_by_admin_id !== null,
                fn () => $this->reviewedBy ? [
                    'id' => $this->reviewedBy->id,
                    'name' => trim("{$this->reviewedBy->first_name} {$this->reviewedBy->last_name}"),
                ] : null,
            ),
            'queue_position' => $this->queue_position,
            'queue_ahead_count' => $ahead,
            'queue_ahead_description' => match (true) {
                $ahead === null => null,
                $ahead === 0 => "You're next in line",
                $ahead === 1 => '1 driver ahead of you',
                default => "{$ahead} drivers ahead of you",
            },
            'estimated_available_at' => $this->estimated_available_at?->toISOString(),
            // Naira, as a string ("500.00"); fee_amount_kobo is the same in kobo.
            'fee_amount' => $this->fee_amount,
            'fee_amount_kobo' => $this->fee_amount === null ? null : (int) round((float) $this->fee_amount * 100),
            'fee_waived' => $this->fee_waived,
            // hire_purchase: still paying off an E-tiGo car; tier: the driver's tier waives it.
            'fee_waiver_reason' => $this->fee_waiver_reason,
            'fee_charged_at' => $this->fee_charged_at?->toISOString(),
            'fee_refunded_at' => $this->fee_refunded_at?->toISOString(),
            // Cancelling before this time refunds the fee (null: nothing to refund).
            'free_cancellation_until' => $this->status->isTerminal()
                ? null
                : EvReservationFeeService::freeCancellationUntil($this->resource)?->toISOString(),
            // When the stall stops being held if the driver has not started
            // charging: the timestamp behind check_in_time.
            'check_in_deadline' => $this->checkInDeadline()?->toISOString(),
            'estimated_charge_minutes' => $this->estimated_charge_minutes,
            // Operations cancelled or ended it from the dashboard.
            'ended_by_operations' => $this->ended_by_admin_id !== null || $this->ended_at !== null,
            'end_reason' => $this->end_reason,
            'ended_at' => $this->ended_at?->toISOString(),
            'ended_by' => $this->when(
                $isAdmin && $this->ended_by_admin_id !== null,
                fn () => $this->endedBy ? [
                    'id' => $this->endedBy->id,
                    'name' => trim("{$this->endedBy->first_name} {$this->endedBy->last_name}"),
                ] : null,
            ),
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

    private function checkInDeadline(): ?CarbonInterface
    {
        $minutes = (int) config('ev_charging.reservation_expiry_minutes', 30);

        return match ($this->status) {
            EvReservationStatus::Confirmed => $this->confirmed_slot_at?->copy()->addMinutes($minutes),
            EvReservationStatus::Reserved => $this->reserved_at?->copy()->addMinutes($minutes),
            default => null,
        };
    }
}
