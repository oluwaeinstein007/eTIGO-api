<?php

namespace App\Http\Resources\Concerns;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * What each viewer of a ride sees of a third-party booking (Phase 2): the
 * booker and admins get the rider and delivery details, the PIN and share
 * token only as the policy allows; the driver gets the rider's first name
 * and phone instead of the booker's contact details.
 */
trait PresentsThirdPartyRide
{
    /**
     * @return array<string, mixed>
     */
    protected function thirdPartyFields(Request $request): array
    {
        $viewer = $request->user();
        $isBooker = $viewer?->id === $this->passenger_id;
        $isDriver = $viewer !== null && $viewer->id === $this->driver_id;
        $seesDetails = $isBooker || (bool) $viewer?->isAdmin();

        return [
            'is_third_party' => (bool) $this->is_third_party,
            'third_party' => $this->when($this->is_third_party && $seesDetails, fn () => [
                'rider_name' => $this->rider_name,
                'rider_phone' => $this->rider_phone,
                'pin_recipient' => $this->pin_recipient?->value,
                'tracking_recipient' => $this->tracking_recipient?->value,
                'rider_notification_status' => $this->rider_notification_status?->value,
                'rider_notification_channel' => $this->rider_notification_channel,
                'rider_notified_at' => $this->rider_notified_at,
                'booker_sees_pin' => $this->resource->bookerSeesPin(),
                'booker_sees_tracking' => $this->resource->bookerSeesTracking(),
            ]),
            // The person the driver picks up; the driver can call them until
            // the trip ends. Own rides read the passenger only when it is
            // loaded, so ride lists do not query once per ride.
            'rider' => $this->is_third_party || $this->relationLoaded('passenger') ? [
                'first_name' => $this->resource->riderFirstName(),
                'phone' => $isDriver && $this->resource->isTerminal() ? null : $this->resource->riderPhone(),
                'is_third_party' => (bool) $this->is_third_party,
            ] : null,
        ];
    }

    protected function passengerField(Request $request): mixed
    {
        // The driver of a ride booked for someone else does not need the
        // booker's contact details; they call the rider.
        if ($this->is_third_party && $this->driver_id !== null && $request->user()?->id === $this->driver_id) {
            return $this->whenLoaded('passenger', fn () => [
                'id' => $this->passenger->id,
                'first_name' => $this->passenger->first_name,
            ]);
        }

        return new UserResource($this->whenLoaded('passenger'));
    }

    protected function shareTokenField(Request $request): ?string
    {
        if ($request->user()?->id === $this->passenger_id && ! $this->resource->bookerSeesTracking()) {
            return null;
        }

        return $this->share_token;
    }

    protected function pinCodeField(Request $request): mixed
    {
        return $this->when(
            $request->user()?->id === $this->passenger_id,
            fn () => $this->resource->bookerSeesPin() ? Cache::get("ride:{$this->id}:pin_code") : null,
        );
    }
}
