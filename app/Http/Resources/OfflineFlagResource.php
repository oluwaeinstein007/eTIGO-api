<?php

namespace App\Http\Resources;

use App\Enums\SanctionTier;
use App\Models\OfflineTripFlag;
use App\Services\SanctionService;
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
            // Level 3 only: until then the driver can dispute and no admin
            // can uphold the flag or confirm the deactivation.
            'deactivation_response_due_at' => $this->when(
                $this->deactivation_response_due_at !== null && $this->sanction_tier === SanctionTier::Deactivation,
                fn () => $this->deactivation_response_due_at->toISOString(),
            ),
            // Set once an admin confirms the Level 3 deactivation;
            // reversed_at is set if a super admin later overturns the flag.
            'deactivation' => $this->when($this->deactivation_confirmed_at !== null, fn () => [
                'confirmed_at' => $this->deactivation_confirmed_at->toISOString(),
                'reversed_at' => $this->deactivation_reversed_at?->toISOString(),
                ...($isAdmin ? [
                    'confirmed_by' => $this->adminSummary('deactivationConfirmedBy'),
                    'notes' => $this->deactivation_notes,
                ] : []),
            ]),
            // An admin verified the driver took cash for the trip.
            'cash_taking' => $this->when($this->cash_taking_verified_at !== null, fn () => [
                'verified_at' => $this->cash_taking_verified_at->toISOString(),
                ...($isAdmin ? [
                    'verified_by' => $this->adminSummary('cashTakingVerifiedBy'),
                    'notes' => $this->cash_taking_notes,
                ] : []),
            ]),
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
            // The driver profile's status (approved, suspended, deactivated):
            // driver.is_active is the login account and does not change.
            'driver_status' => $this->when($isAdmin && $this->driverProfileLoaded(), fn () => $this->driverProfile()?->status?->value),
            // When the driver's automatic 48-hour suspension lifts; null for
            // any other status, including suspended pending review.
            'driver_suspension_ends_at' => $this->when($isAdmin && $this->driverProfileLoaded(), fn () => $this->suspensionEndsAt()),
            'allowed_actions' => $this->when($isAdmin && $this->driverProfileLoaded(), fn () => $this->allowedActions($request->user())),
            'passenger' => new UserResource($this->whenLoaded('passenger')),
            'ride' => new RideResource($this->whenLoaded('ride')),
            'resolved_by' => new UserResource($this->whenLoaded('resolvedBy')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function suspensionEndsAt(): ?string
    {
        $driver = $this->driverProfile();

        if (! $driver?->isSuspended() || $driver->suspension_reason !== SanctionService::AUTOMATIC_SUSPENSION_REASON || ! $driver->suspended_at) {
            return null;
        }

        return $driver->suspended_at->copy()->addHours((int) config('offline_detection.suspension_hours', 48))->toISOString();
    }

    private function driverProfileLoaded(): bool
    {
        return $this->relationLoaded('driver') && $this->driver?->relationLoaded('driver');
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function adminSummary(string $relation): ?array
    {
        $admin = $this->relationLoaded($relation) ? $this->{$relation} : null;

        return $admin ? ['id' => $admin->id, 'name' => trim($admin->first_name.' '.$admin->last_name)] : null;
    }
}
