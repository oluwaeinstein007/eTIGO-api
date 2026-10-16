<?php

namespace App\Http\Resources;

use App\Models\AppSetting;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A scheduled ride as a driver sees it when browsing or holding
 * reservations: the trip and the pickup time, but no passenger contact
 * details (those come with the normal ride once it is dispatched).
 *
 * @mixin Ride
 */
class DriverScheduledRideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (! $request->attributes->has('scheduled_ride_settings')) {
            $request->attributes->set('scheduled_ride_settings', AppSetting::scheduledRides());
        }

        $settings = $request->attributes->get('scheduled_ride_settings');
        $scheduled = $this->scheduledRide;

        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'scheduled_pickup_at' => $scheduled?->scheduled_pickup_at,
            // Be online by then: matching (or the hand-over to you) starts here.
            'dispatch_at' => $scheduled?->dispatchAt($settings['dispatch_lead_minutes']),
            'pickup' => [
                'lat' => $this->pickup_lat,
                'lng' => $this->pickup_lng,
                'address' => $this->pickup_address,
            ],
            'destination' => [
                'lat' => $this->destination_lat,
                'lng' => $this->destination_lng,
                'address' => $this->destination_address,
            ],
            'fare_estimate_amount' => $this->fare_estimate_amount,
            'fare_currency' => $this->fare_currency,
            'distance_km' => data_get($this->pricing_snapshot, 'distance_km'),
            'duration_minutes' => data_get($this->pricing_snapshot, 'duration_minutes'),
            'payment_method' => $this->payment_method?->value,
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'passenger' => $this->whenLoaded('passenger', fn () => [
                'first_name' => $this->passenger->first_name,
                'rating' => $this->passenger->averageRating(),
            ]),
            'reserved_by_me' => $scheduled?->reserved_driver_id === $request->user()?->id,
            'reserved_at' => $scheduled?->reserved_at,
        ];
    }
}
