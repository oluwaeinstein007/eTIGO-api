<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesUploadUrls;
use App\Models\LostItem;
use App\Models\LostItemMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lost item report as the viewer may see it. The passenger and the
 * driver see each other's first name, and each other's phone number only
 * while the driver has the item (`contact`); support sees everything,
 * including internal notes.
 *
 * @mixin LostItem
 */
class LostItemResource extends JsonResource
{
    use ResolvesUploadUrls;

    public function toArray(Request $request): array
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $isAdmin = $viewer->isAdmin();
        $isPassenger = $this->isPassenger($viewer);
        $isDriver = ! $isPassenger && $this->isDriver($viewer);

        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'ride_id' => $this->ride_id,
            'viewer_role' => $isAdmin ? 'support' : ($isPassenger ? 'passenger' : 'driver'),
            'reported_by' => $this->reported_by,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'photos' => collect($this->photos ?? [])->map(fn (string $path) => $this->resolveUploadUrl($path))->all(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_open' => $this->isOpen(),
            'driver_response' => $this->driver_response,
            'contact_phone' => $this->when($isAdmin || $isPassenger, $this->contact_phone),
            'escalated' => $this->escalated_at !== null,
            'escalated_at' => $this->escalated_at,
            'escalation_reason' => $this->when($isAdmin || $isPassenger, $this->escalation_reason),
            'escalation_source' => $this->when($isAdmin, $this->escalation_source),
            'closure_reason' => $this->closure_reason?->value,
            'closure_reason_label' => $this->closure_reason?->label(),
            'resolution_notes' => $this->resolution_notes,
            'return_fee' => [
                'amount' => $this->returnFeeAmount(),
                'currency' => 'NGN',
                // pending, charged, balance_due, waived, or null when none is due
                'status' => $this->return_fee_status,
            ],
            'contact' => $this->when(! $isAdmin, fn () => $this->counterpartContact($isPassenger)),
            'ride' => $this->when($this->relationLoaded('ride'), fn () => $this->rideSummary($isAdmin || $isPassenger)),
            'passenger' => $this->when($this->relationLoaded('passenger') && ! $isPassenger, fn () => $isAdmin
                ? new UserResource($this->passenger)
                : ['first_name' => $this->ride?->riderFirstName() ?? $this->passenger?->first_name]),
            'driver' => $this->when($this->relationLoaded('driver') && ! $isDriver, fn () => $isAdmin
                ? ($this->driver ? new UserResource($this->driver) : null)
                : ['first_name' => $this->driver?->first_name]),
            'resolved_by' => $this->when($isAdmin && $this->relationLoaded('resolvedBy'), fn () => $this->resolvedBy ? new UserResource($this->resolvedBy) : null),
            'messages' => $this->when($this->relationLoaded('messages'), fn () => LostItemMessageResource::collection(
                $this->messages->filter(fn (LostItemMessage $message) => $isAdmin || ! $message->is_internal)->values(),
            )),
            'available_actions' => $isAdmin ? [] : $this->actionsFor($viewer),
            'driver_notified_at' => $this->driver_notified_at,
            'driver_responded_at' => $this->driver_responded_at,
            'returned_at' => $this->returned_at,
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The other side's name and phone number, once the driver has the item.
     *
     * @return array{first_name: ?string, phone: ?string}|null
     */
    private function counterpartContact(bool $viewerIsPassenger): ?array
    {
        if (! $this->sharesContactDetails()) {
            return null;
        }

        if ($viewerIsPassenger) {
            return $this->driver ? ['first_name' => $this->driver->first_name, 'phone' => $this->driver->phone] : null;
        }

        return [
            'first_name' => $this->ride?->riderFirstName() ?? $this->passenger?->first_name,
            'phone' => $this->passengerContactPhone(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rideSummary(bool $withVehicle): array
    {
        $ride = $this->ride;
        $vehicle = $withVehicle ? $this->driver?->driver?->vehicle : null;

        return [
            'id' => $ride->id,
            'reference_number' => $ride->reference_number,
            'pickup_address' => $ride->pickup_address,
            'destination_address' => $ride->destination_address,
            'started_at' => $ride->started_at,
            'completed_at' => $ride->completed_at,
            'city' => $ride->relationLoaded('city') ? $ride->city?->name : null,
            'is_third_party' => (bool) $ride->is_third_party,
            'vehicle' => $vehicle ? [
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'colour' => $vehicle->colour,
                'plate_number' => $vehicle->plate_number,
            ] : null,
        ];
    }
}
