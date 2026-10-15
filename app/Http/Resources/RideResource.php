<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

class RideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'city_id' => $this->city_id,
            'vehicle_class_id' => $this->vehicle_class_id,
            'passenger_id' => $this->passenger_id,
            'driver_id' => $this->driver_id,
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
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'share_token' => $this->share_token,
            'fare_estimate_amount' => $this->fare_estimate_amount,
            'final_fare_amount' => $this->final_fare_amount,
            'discount_amount' => $this->discount_amount,
            'fare_currency' => $this->fare_currency,
            'promo_code' => $this->when($this->promo_code_id, fn () => [
                'id' => $this->promo_code_id,
                'code' => $this->whenLoaded('promoCode', fn () => $this->promoCode->code),
            ]),
            'distance_km' => data_get($this->pricing_snapshot, 'distance_km'),
            'duration_minutes' => data_get($this->pricing_snapshot, 'duration_minutes'),
            'co2_saved_kg' => (float) (\App\Models\TripCarbonScore::where('ride_id', $this->id)->where('user_id', $this->passenger_id)->value('co2_saved') ?? round(((float) data_get($this->pricing_snapshot, 'distance_km', 0)) * 0.12, 2)),
            'payment_method' => $this->payment_method?->value,
            'payment_status' => $this->payment_status?->value,
            'tip_amount' => $this->relationLoaded('payment') ? $this->payment?->tip_amount : null,
            'payment' => new PaymentResource($this->whenLoaded('payment')),
            'cancellation_reason' => $this->cancellation_reason,
            'city' => new CityResource($this->whenLoaded('city')),
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'passenger' => new UserResource($this->whenLoaded('passenger')),
            'driver' => new UserResource($this->whenLoaded('driver')),
            'cancelled_by' => new UserResource($this->whenLoaded('cancelledByUser')),
            'payment_details' => $this->when($this->relationLoaded('payment') && $this->payment, function () {
                $payment = $this->payment;

                return [
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'method' => $payment->method?->value,
                    'status' => $payment->status?->value,
                    'card_brand' => $payment->paymentMethod?->card_brand,
                    'card_last_four' => $payment->paymentMethod?->card_last_four,
                    'tip_amount' => $payment->tip_amount ? (float) $payment->tip_amount : null,
                ];
            }),
            'matched_at' => $this->matched_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'pin_code' => $this->when(
                $request->user()?->id === $this->passenger_id,
                fn () => Cache::get("ride:{$this->id}:pin_code"),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
